<?php

namespace App\Sync;

use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use Illuminate\Support\Facades\DB;

/** PostgreSQL records intent; Redis only transports wakeups. The advisory lock fences execution. */
class SyncRequests
{
    public function request(int $accountId, string $trigger = 'scheduled'): array
    {
        return DB::transaction(function () use ($accountId, $trigger) {
            $account = MailAccount::query()->lockForUpdate()->findOrFail($accountId);
            $pending = $account->sync_requested_generation > $account->sync_completed_generation;
            $running = $account->sync_started_generation > $account->sync_completed_generation;
            $new = $account->sync_requested_generation <= $account->sync_started_generation;
            $priority = ['scheduled' => 0, 'initial' => 0, 'continuation' => 0, 'idle' => 1, 'manual' => 2];
            $promote = ($priority[$trigger] ?? 0) > ($priority[$account->sync_request_trigger] ?? 0);
            $recovered = false;
            if ($running && $trigger === 'manual' && app(AccountSyncLock::class)->acquire($account)) {
                // A claimed generation without a live writer is crashed, not running.
                app(AccountSyncLock::class)->release($account);
                $running = false;
                $recovered = true;
                $account->sync_started_generation = $account->sync_completed_generation;
            }
            if ($new) {
                $account->sync_requested_generation++;
                $account->sync_requested_at = now();
            }
            if (! $pending || $promote) {
                $account->sync_request_trigger = $trigger;
            }
            $redeliver = in_array($trigger, ['manual', 'idle'], true)
                && $account->sync_dispatched_at?->lt(now()->subSecond());
            $dispatch = ! $running && ($new || $promote || $recovered || $redeliver);
            if ($dispatch) {
                $account->sync_dispatched_at = now();
                $account->next_sync_at = now();
            }
            $account->save();
            if ($dispatch) {
                $this->dispatch($account);
            }

            return ['generation' => $account->sync_requested_generation, 'state' => $running ? 'syncing' : 'queued', 'accepted_at' => microtime(true)];
        });
    }

    /** Called only while holding the account advisory lock. A crashed generation is replayed. */
    public function claim(MailAccount $account): bool
    {
        return DB::transaction(function () use ($account) {
            $locked = MailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $account->setRawAttributes($locked->getAttributes(), true);
            if ($account->sync_requested_generation <= $account->sync_completed_generation) {
                return false;
            }
            $account->update(['sync_started_generation' => $account->sync_requested_generation]);

            return true;
        });
    }

    /** Acknowledge only the generation actually processed, never a request accepted during IO. */
    public function finish(int $accountId, int $generation, bool $continue): void
    {
        DB::transaction(function () use ($accountId, $generation, $continue) {
            $account = MailAccount::withTrashed()->lockForUpdate()->findOrFail($accountId);
            $account->sync_completed_generation = max($generation, $account->sync_completed_generation);
            if ($continue && $account->sync_requested_generation <= $generation) {
                $account->sync_requested_generation = $generation + 1;
                $account->sync_request_trigger = 'continuation';
                $account->sync_requested_at = now();
            }
            $pending = $account->sync_requested_generation > $account->sync_completed_generation;
            $eligible = $account->enabled && $account->sync_enabled && ! $account->trashed()
                && ! in_array($account->sync_status, ['auth_failed', 'backing_off', 'error'], true);
            if ($pending && $eligible) {
                $account->sync_dispatched_at = now();
            }
            $account->save();
            if ($pending && $eligible) {
                $this->dispatch($account);
            }
        });
    }

    /** Lost queue messages and worker crashes cannot erase intent. No lease can bypass PG fencing. */
    public function recover(): void
    {
        MailAccount::query()->where('enabled', true)->where('sync_enabled', true)
            ->where('sync_status', '!=', 'auth_failed')
            ->whereColumn('sync_requested_generation', '>', 'sync_completed_generation')
            ->where(fn ($q) => $q->whereNull('sync_dispatched_at')->orWhere('sync_dispatched_at', '<', now()->subSeconds(30)))
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now())->orWhere('sync_status', 'syncing'))
            ->limit(500)->get()->each(function (MailAccount $account) {
                $account->update(['sync_dispatched_at' => now()]);
                $this->dispatch($account);
            });
    }

    private function dispatch(MailAccount $account): void
    {
        $id = $account->id;
        $trigger = $account->sync_request_trigger;
        DB::afterCommit(static fn () => SyncAccountJob::dispatch($id, $trigger)->afterCommit());
    }
}
