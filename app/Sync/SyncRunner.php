<?php

namespace App\Sync;

use App\Connectors\Imap\ImapFailure;
use App\Models\MailAccount;
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
    public function run(int $accountId, string $trigger = 'scheduled'): string
    {
        $account = MailAccount::query()->find($accountId);
        if ($account === null || ! $account->enabled || ! $account->sync_enabled) {
            return 'skipped';
        }
        if (! $this->lock->acquire($account)) {
            return 'locked';
        }

        $runId = null;
        try {
            $account->refresh();
            if (! $account->enabled || ! $account->sync_enabled) {
                return 'skipped';
            }
            $runId = DB::table('sync_runs')->insertGetId([
                'mail_account_id' => $account->id, 'trigger' => $trigger, 'started_at' => now(),
            ]);
            $account->update(['sync_status' => 'syncing', 'last_sync_started_at' => now()]);

            $stats = $this->driver->sync($account);

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
            // Never log the message or trace: they can carry connection details.
            Log::error('Mail synchronization failed unexpectedly.', ['account_id' => $account->id, 'exception' => $e::class]);

            return $this->failed($account, $runId, 'unexpected_error', 'Synchronization failed unexpectedly; it will be retried.');
        } finally {
            $this->lock->release($account);
        }
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
        DB::table('sync_runs')->where('id', $runId)->update([
            'finished_at' => now(), 'status' => $status, 'stats' => json_encode($stats),
            'error_code' => $code, 'error_message' => $message,
        ]);
    }
}
