<?php

namespace App\Sync;

use App\Connectors\Imap\ImapFailure;
use App\Models\MailAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs one bounded synchronization pass for one account and persists its status. */
class SyncRunner
{
    private const BACKOFF_MINUTES = [1, 2, 5, 10, 30, 60];

    public function __construct(
        private readonly AccountSyncLock $lock,
        private readonly AccountSyncDriver $driver,
    ) {}

    /** @return string one of skipped, locked, success, partial, failed, aborted */
    public function run(int $accountId, string $trigger = 'scheduled', bool $requested = false): string
    {
        app(SyncTelemetry::class)->start();
        $workerStarted = now();
        $started = microtime(true);
        $generation = null;
        $continue = false;
        $account = MailAccount::query()->find($accountId);
        if ($account === null || ! $this->eligible($account, $requested)) {
            return 'skipped';
        }
        if (! $this->lock->acquire($account)) {
            return 'locked';
        }

        app(SyncTelemetry::class)->mark('lock_acquired');
        $runId = null;
        try {
            $account->refresh();
            if (! $this->eligible($account, $requested)) {
                return 'skipped';
            }
            if ($requested) {
                if (! app(SyncRequests::class)->claim($account)) {
                    return 'skipped';
                }
                $generation = $account->sync_started_generation;
                $trigger = $account->sync_request_trigger;
            }
            $runId = DB::table('sync_runs')->insertGetId([
                'mail_account_id' => $account->id, 'trigger' => $trigger, 'started_at' => now(),
                'request_generation' => $generation, 'requested_at' => $requested ? $account->sync_requested_at : null,
                'worker_started_at' => $workerStarted,
                'timings' => json_encode(['lock_acquired_ms' => (microtime(true) - $started) * 1000]),
            ]);
            $account->update(['sync_status' => 'syncing', 'last_sync_started_at' => now()]);

            // The driver reads the run trigger without persisting protocol state on the account.
            $account->syncContext = ['trigger' => $trigger, 'generation' => $generation];
            $stats = $this->driver->sync($account);
            $continue = (bool) $stats['remaining'];

            return $this->succeeded($account, $runId, $stats);
        } catch (SyncAborted) {
            $this->finishRun($runId, 'aborted', [], null, null);
            $account->refresh();
            $account->update([
                'sync_status' => 'idle',
                'last_sync_finished_at' => now(),
                'next_sync_at' => $account->enabled && $account->sync_enabled && ! $account->trashed() ? now() : null,
            ]);

            return 'aborted';
        } catch (ImapFailure $failure) {
            return $this->failed($account, $runId, $failure->category, ImapFailure::safeMessage($failure->category));
        } catch (Throwable $e) {
            if ($this->transientDatabaseConflict($e)) {
                // A lock-order deadlock/serialization failure is a lost race, not a provider failure: the
                // transaction rolled back, so record the run as aborted and replay via a continuation.
                Log::warning('Mail synchronization lost a database race; retrying.', ['account_id' => $account->id]);
                $this->finishRun($runId, 'aborted', [], null, null);
                $account->refresh();
                $account->update(['sync_status' => 'idle', 'last_sync_finished_at' => now()]);
                $continue = true;

                return 'aborted';
            }
            // Never log the message or trace: they can carry connection details.
            Log::error('Mail synchronization failed unexpectedly.', ['account_id' => $account->id, 'exception' => $e::class]);

            return $this->failed($account, $runId, 'unexpected_error', 'Synchronization failed unexpectedly; it will be retried.');
        } finally {
            try {
                if ($generation !== null) {
                    app(SyncRequests::class)->finish($accountId, $generation, $continue);
                }
            } finally {
                $this->lock->release($account);
            }
        }
    }

    private function transientDatabaseConflict(Throwable $e): bool
    {
        return $e instanceof QueryException && in_array((string) $e->getCode(), ['40P01', '40001'], true);
    }

    private function eligible(MailAccount $account, bool $requested): bool
    {
        return $account->enabled && $account->sync_enabled && ! $account->trashed() && $account->sync_status !== 'auth_failed'
            && ! ($requested && in_array($account->sync_status, ['backing_off', 'error'], true) && $account->next_sync_at?->isFuture());
    }

    private function succeeded(MailAccount $account, int $runId, array $stats): string
    {
        $account->refresh();
        if ($stats['partial'] > 0) {
            $this->finishRun($runId, 'partial', $stats, ImapFailure::FOLDER, ImapFailure::safeMessage(ImapFailure::FOLDER));
            $this->recordFailure($account, ImapFailure::FOLDER, ImapFailure::safeMessage(ImapFailure::FOLDER), 'backing_off');

            return 'partial';
        }
        $this->finishRun($runId, 'success', $stats, null, null);
        $account->update([
            'sync_status' => $stats['remaining'] ? 'syncing' : 'idle',
            'last_sync_finished_at' => now(),
            'last_successful_sync_at' => now(),
            'next_sync_at' => $stats['remaining'] ? now() : now()->addSeconds($account->sync_interval_seconds),
            'consecutive_failures' => 0,
            'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null,
        ]);

        return 'success';
    }

    private function failed(MailAccount $account, ?int $runId, string $code, string $message): string
    {
        $this->finishRun($runId, 'failed', [], $code, $message);
        $account->refresh();
        $status = match ($code) {
            ImapFailure::AUTH => 'auth_failed',
            ImapFailure::TLS, ImapFailure::DESTINATION => 'error',
            default => 'backing_off',
        };
        $this->recordFailure($account, $code, $message, $status);

        return 'failed';
    }

    private function recordFailure(MailAccount $account, string $code, string $message, string $status): void
    {
        $failures = $account->consecutive_failures + 1;
        $next = match ($status) {
            'auth_failed' => null, // no retries: repeated bad logins can lock the mailbox
            'error' => now()->addHour(),
            default => now()->addSeconds($this->backoffSeconds($failures)),
        };
        if (! $account->enabled || ! $account->sync_enabled || $account->trashed()) {
            $next = null;
        }
        $account->update([
            'sync_status' => $status, 'consecutive_failures' => $failures,
            'last_sync_finished_at' => now(), 'next_sync_at' => $next,
            'last_error_code' => $code, 'last_error_message' => $message, 'last_error_at' => now(),
        ]);
    }

    public function backoffSeconds(int $failures): int
    {
        $minutes = self::BACKOFF_MINUTES[min($failures, count(self::BACKOFF_MINUTES)) - 1];

        return (int) round($minutes * 60 * (1 + random_int(-100, 100) / 1000));
    }

    private function finishRun(?int $runId, string $status, array $stats, ?string $code, ?string $message): void
    {
        if ($runId === null) {
            return;
        }
        app(SyncTelemetry::class)->mark('run_finished');
        DB::table('sync_runs')->where('id', $runId)->update([
            'finished_at' => now(), 'status' => $status, 'stats' => json_encode($stats),
            'error_code' => $code, 'error_message' => $message,
            'timings' => json_encode(app(SyncTelemetry::class)->all()),
        ]);
    }
}
