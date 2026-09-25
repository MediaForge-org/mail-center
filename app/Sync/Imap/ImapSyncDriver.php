<?php

namespace App\Sync\Imap;

use App\Accounts\Credentials\CredentialVault;
use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Ingestion\MessageIngestor;
use App\Models\MailAccount;
use App\Models\RemoteFolder;
use App\Sync\AccountSyncDriver;
use App\Sync\AccountSyncLock;
use App\Sync\SyncAborted;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImapSyncDriver implements AccountSyncDriver
{
    public function __construct(
        private readonly ImapClient $client,
        private readonly CredentialVault $vault,
        private readonly MessageIngestor $ingestor,
        private readonly AccountSyncLock $lock,
    ) {}

    public function sync(MailAccount $account): array
    {
        $stats = ['remaining' => false, 'new' => 0, 'linked' => 0, 'removed' => 0, 'failures' => 0, 'partial' => 0];
        $deadline = microtime(true) + (int) config('mailcenter.imap.work_seconds');
        $this->client->connect($account, $this->vault->forAccount($account));
        try {
            $this->discover($account, $this->client->folders());
            // Repeated passes give each folder one window in turn (fairness) until the budget is spent.
            for ($pass = 0; $pass < 1000; $pass++) {
                $stats['remaining'] = $this->pass($account, $deadline, $stats);
                if (! $stats['remaining'] || microtime(true) >= $deadline) {
                    break;
                }
            }
            if (! $stats['remaining'] && $stats['partial'] === 0) {
                $folders = $account->remoteFolders()->where('selectable', true)->where('sync_enabled', true)->whereNull('removed_at')->get();
                if ($folders->every(fn (RemoteFolder $folder) => $folder->fresh()?->backfill_completed_at !== null)) {
                    $this->reconcileMessages($account);
                }
            }
        } finally {
            $this->client->close();
        }

        return $stats;
    }

    /** One round over the enabled folders; true while any folder still has work. */
    private function pass(MailAccount $account, float $deadline, array &$stats): bool
    {
        $remaining = false;
        $stats['partial'] = 0;
        $folders = $account->remoteFolders()->where('selectable', true)->where('sync_enabled', true)
            ->whereNull('removed_at')->orderByRaw("CASE WHEN role = 'inbox' THEN 0 WHEN role = 'sent' THEN 1 ELSE 2 END")
            ->orderBy('id')->get();
        foreach ($folders as $folder) {
            if (microtime(true) >= $deadline) {
                return true;
            }
            $this->assertActive($account);
            try {
                $examined = $this->client->examine($folder->raw_name);
            } catch (ImapFailure $failure) {
                if ($failure->category !== ImapFailure::FOLDER) {
                    throw $failure;
                }
                // Only a successful full LIST proves absence; a refused EXAMINE is partial coverage.
                $stats['partial']++;

                continue;
            }
            $this->identity($account, $folder, $examined);
            $remaining = $this->syncFolder($account, $folder, $examined, $deadline, $stats) || $remaining;
        }

        return $remaining;
    }

    private function discover(MailAccount $account, array $observed): void
    {
        $names = [];
        foreach ($observed as $item) {
            $names[] = $item['raw_name'];
            $existing = $account->remoteFolders()->where('raw_name', $item['raw_name'])->first();
            if ($existing) {
                $existing->update([
                    'name' => $item['name'], 'delimiter' => $item['delimiter'],
                    'attributes' => $item['attributes'], 'role' => $item['role'],
                    'selectable' => $item['selectable'], 'removed_at' => null,
                ]);
            } else {
                $account->remoteFolders()->create([
                    ...$item,
                    // M2 synchronizes INBOX only; other folders are discovered and can be enabled per folder.
                    'sync_enabled' => $item['selectable'] && $item['role'] === 'inbox',
                ]);
            }
        }
        foreach ($account->remoteFolders()->whereNull('removed_at')->get() as $folder) {
            if (! in_array($folder->raw_name, $names, true)) {
                DB::transaction(function () use ($folder) {
                    $folder->update(['removed_at' => now()]);
                    $this->removeLocations($folder, 'folder_removed');
                });
            }
        }
    }

    private function identity(MailAccount $account, RemoteFolder $folder, array $examine): void
    {
        $validity = (int) $examine['uidvalidity'];
        $initialHigh = max(0, (int) $examine['uidnext'] - 1);
        if ($folder->uidvalidity === null) {
            $folder->update([
                'uidvalidity' => $validity, 'sync_high_uid' => $initialHigh,
                'backfill_low_uid' => $initialHigh + 1, 'last_exists' => $examine['exists'],
            ]);

            return;
        }
        if ((int) $folder->uidvalidity === $validity) {
            $folder->update(['last_exists' => $examine['exists']]);

            return;
        }
        DB::transaction(function () use ($account, $folder, $validity, $initialHigh, $examine) {
            $this->removeLocations($folder, 'uidvalidity_reset');
            DB::table('sync_failures')->where('remote_folder_id', $folder->id)->where('status', '!=', 'resolved')
                ->update(['status' => 'obsolete']);
            $folder->update([
                'uidvalidity' => $validity, 'sync_high_uid' => $initialHigh,
                'backfill_low_uid' => $initialHigh + 1, 'backfill_completed_at' => null,
                'last_reconciled_at' => null, 'reconcile_cycle_id' => null,
                'last_flag_scan_at' => null, 'last_exists' => $examine['exists'],
            ]);
            DB::table('audit_events')->insert([
                'user_id' => $account->user_id, 'actor_type' => 'sync',
                'action' => 'sync.uidvalidity_reset', 'subject_type' => 'remote_folder',
                'subject_id' => $folder->id, 'context' => json_encode(['new_uidvalidity' => $validity]),
                'created_at' => now(),
            ]);
        });
    }

    private function syncFolder(MailAccount $account, RemoteFolder $folder, array $examined, float $deadline, array &$stats): bool
    {
        $window = (int) config('mailcenter.imap.uid_window');
        $upper = max(0, (int) $examined['uidnext'] - 1);
        $this->retryQuarantined($account, $folder, $stats);
        while ($folder->sync_high_uid < $upper && microtime(true) < $deadline) {
            $low = (int) $folder->sync_high_uid + 1;
            $high = min($upper, $low + $window - 1);
            $done = $this->processWindow($account, $folder, $low, $high, $deadline, false, $stats);
            $folder->update(['sync_high_uid' => $done]);
        }
        if ($folder->sync_high_uid < $upper) {
            return true;
        }
        if ($folder->backfill_completed_at === null && microtime(true) < $deadline) {
            $high = (int) $folder->backfill_low_uid - 1;
            if ($high < 1) {
                $folder->update(['backfill_completed_at' => now()]);
            } else {
                $low = max(1, $high - $window + 1);
                $done = $this->processWindow($account, $folder, $low, $high, $deadline, true, $stats);
                $folder->update([
                    'backfill_low_uid' => $done,
                    'backfill_completed_at' => $done <= 1 ? now() : null,
                ]);
            }
        }
        if ($folder->backfill_completed_at === null) {
            return true;
        }
        // Membership scans use numeric UID windows, not EXISTS counts or unbounded SEARCH ALL.
        $state = $account->sync_state ?: ['version' => 1];
        $cursor = (int) (($state['membership'] ?? [])[(string) $folder->id] ?? 1);
        while ($cursor <= $upper && microtime(true) < $deadline) {
            $this->assertActive($account);
            $high = min($upper, $cursor + $window - 1);
            $this->membership($folder, $cursor, $high, $stats);
            $cursor = $high + 1;
            $state['membership'][(string) $folder->id] = $cursor;
            $account->update(['sync_state' => $state]);
        }
        if ($cursor <= $upper) {
            return true;
        }
        $folder->update(['last_reconciled_at' => now(), 'reconcile_cycle_id' => $account->id]);
        unset($state['membership'][(string) $folder->id]);
        $account->update(['sync_state' => $state]);
        if ($folder->last_flag_scan_at === null || $folder->last_flag_scan_at->lt(now()->subSeconds((int) config('mailcenter.imap.flag_scan_seconds')))) {
            $this->scanFlags($folder, $upper, $deadline);
        }
        $folder->update(['last_synced_at' => now()]);

        return false;
    }

    /**
     * Processes UIDs of [$low, $high] (ascending for new mail, descending for backfill) until the
     * window or the time budget is exhausted, and returns the checkpoint to persist: the highest
     * completed UID (forward) or the lowest completed UID (backfill).
     */
    private function processWindow(MailAccount $account, RemoteFolder $folder, int $low, int $high, float $deadline, bool $descending, array &$stats): int
    {
        $uids = $this->client->search($low, $high);
        if ($descending) {
            $uids = array_reverse($uids);
        }
        $checkpoint = $descending ? $high + 1 : $low - 1;
        foreach (array_chunk($uids, (int) config('mailcenter.imap.uids_per_batch')) as $batch) {
            if (microtime(true) >= $deadline) {
                return $checkpoint;
            }
            $this->assertActive($account);
            foreach ($batch as $uid) {
                $this->processUid($account, $folder, $uid, $stats);
                $checkpoint = $uid;
            }
        }

        return $descending ? $low : $high;
    }

    private function retryQuarantined(MailAccount $account, RemoteFolder $folder, array &$stats): void
    {
        $due = DB::table('sync_failures')->where('remote_folder_id', $folder->id)
            ->where('uidvalidity', $folder->uidvalidity)->where('status', 'retryable')
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(50)->pluck('uid');
        foreach ($due as $uid) {
            $this->assertActive($account);
            $this->processUid($account, $folder, (int) $uid, $stats);
        }
    }

    private function processUid(MailAccount $account, RemoteFolder $folder, int $uid, array &$stats): void
    {
        $limit = (int) config('mailcenter.imap.max_message_bytes');
        $known = fn () => DB::table('message_locations')->where('remote_folder_id', $folder->id)
            ->where('uidvalidity', $folder->uidvalidity)->where('uid', $uid)->whereNull('removed_at')->exists();
        if ($known()) {
            $stats['linked']++;

            return;
        }
        try {
            $meta = $this->client->metadata($uid);
            $fetched = null;
            if ($meta !== null && $meta['size'] > $limit) {
                $this->failure($folder, $uid, 'too_large');
                $stats['failures']++;

                return;
            }
            if ($meta !== null) {
                $fetched = $this->client->fetch($uid);
            }
            if ($fetched === null) {
                if ($this->client->search($uid, $uid) !== []) {
                    throw new \RuntimeException('Fetch was incomplete.');
                }
                // Expunged since it was listed: nothing to store, and any quarantine is moot.
                DB::table('sync_failures')->where('remote_folder_id', $folder->id)
                    ->where('uidvalidity', $folder->uidvalidity)->where('uid', $uid)
                    ->whereIn('status', ['retryable', 'manual'])->update(['status' => 'obsolete']);

                return;
            }
            if (strlen($fetched['raw']) > $limit) {
                $this->failure($folder, $uid, 'too_large');
                $stats['failures']++;

                return;
            }
            $this->ingestor->ingest($account, $folder, $uid, (int) $folder->uidvalidity,
                $fetched['raw'], $fetched['flags'], $fetched['internal_date']);
            DB::table('sync_failures')->where('remote_folder_id', $folder->id)
                ->where('uidvalidity', $folder->uidvalidity)->where('uid', $uid)
                ->update(['status' => 'resolved', 'resolved_at' => now()]);
            $stats['new']++;
        } catch (ImapFailure $failure) {
            if ($failure->category !== ImapFailure::MESSAGE) {
                throw $failure; // connection-level problems must not be charged to the message
            }
            $this->failure($folder, $uid, 'message_error');
            $stats['failures']++;
        } catch (SyncAborted $aborted) {
            throw $aborted;
        } catch (Throwable) {
            $this->failure($folder, $uid, 'message_error');
            $stats['failures']++;
        }
    }

    private function failure(RemoteFolder $folder, int $uid, string $code): void
    {
        $existing = DB::table('sync_failures')->where('remote_folder_id', $folder->id)
            ->where('uidvalidity', $folder->uidvalidity)->where('uid', $uid)->first();
        $attempts = $existing ? $existing->attempts + 1 : 1;
        DB::table('sync_failures')->updateOrInsert(
            ['remote_folder_id' => $folder->id, 'uidvalidity' => $folder->uidvalidity, 'uid' => $uid],
            ['attempts' => $attempts, 'error_code' => $code,
                'last_error' => $code === 'too_large' ? 'Message exceeds configured size limit.' : 'Message could not be fetched or stored.',
                'status' => $code === 'too_large' || $attempts >= 5 ? 'manual' : 'retryable',
                'first_failed_at' => $existing->first_failed_at ?? now(), 'last_failed_at' => now(),
                'next_attempt_at' => $code === 'too_large' || $attempts >= 5 ? null : now()->addMinutes(min(60, 2 ** $attempts))]
        );
    }

    private function membership(RemoteFolder $folder, int $low, int $high, array &$stats): void
    {
        $present = array_flip($this->client->search($low, $high));
        $locations = DB::table('message_locations')->where('remote_folder_id', $folder->id)
            ->where('uidvalidity', $folder->uidvalidity)->whereBetween('uid', [$low, $high])
            ->whereNull('removed_at')->get();
        foreach ($locations as $location) {
            if (! isset($present[(int) $location->uid])) {
                DB::table('message_locations')->where('id', $location->id)->update([
                    'removed_at' => now(), 'removed_reason' => 'expunged',
                ]);
                $this->ingestor->refreshRemoteSummary($location->message_id);
                $stats['removed']++;
            }
        }
    }

    private function scanFlags(RemoteFolder $folder, int $upper, float $deadline): void
    {
        for ($low = 1; $low <= $upper && microtime(true) < $deadline; $low += 5000) {
            foreach ($this->client->flags($low, min($upper, $low + 4999)) as $uid => $flags) {
                $location = DB::table('message_locations')->where('remote_folder_id', $folder->id)
                    ->where('uidvalidity', $folder->uidvalidity)->where('uid', $uid)
                    ->whereNull('removed_at')->first();
                if ($location && json_decode($location->flags, true) !== array_values($flags)) {
                    DB::table('message_locations')->where('id', $location->id)->update(['flags' => json_encode(array_values($flags))]);
                    $this->ingestor->refreshRemoteSummary($location->message_id);
                }
            }
        }
        if ($low > $upper) {
            $folder->update(['last_flag_scan_at' => now()]);
        }
    }

    private function removeLocations(RemoteFolder $folder, string $reason): void
    {
        $messageIds = DB::table('message_locations')->where('remote_folder_id', $folder->id)
            ->whereNull('removed_at')->pluck('message_id');
        DB::table('message_locations')->where('remote_folder_id', $folder->id)->whereNull('removed_at')
            ->update(['removed_at' => now(), 'removed_reason' => $reason]);
        foreach ($messageIds as $id) {
            $this->ingestor->refreshRemoteSummary($id);
        }
    }

    private function reconcileMessages(MailAccount $account): void
    {
        if (DB::table('sync_failures')->join('remote_folders', 'remote_folders.id', '=', 'sync_failures.remote_folder_id')
            ->where('remote_folders.mail_account_id', $account->id)->whereIn('sync_failures.status', ['retryable', 'manual'])->exists()) {
            return;
        }
        $missing = DB::table('messages')->where('mail_account_id', $account->id)
            ->whereNotIn('id', DB::table('message_locations')->select('message_id')->whereNull('removed_at'));
        (clone $missing)->where('remote_status', 'unknown')->update([
            'remote_status' => 'missing', 'remote_missing_since' => now(),
        ]);
        (clone $missing)->where('remote_status', 'missing')
            ->where('remote_missing_since', '<', now()->subSeconds((int) config('mailcenter.imap.removal_grace_seconds')))
            ->update(['remote_status' => 'removed', 'remote_removed_at' => now()]);
    }

    private function assertActive(MailAccount $account): void
    {
        $account->refresh();
        if (! $account->enabled || ! $account->sync_enabled || $account->trashed()) {
            throw new SyncAborted('Account synchronization was disabled.');
        }
        $this->lock->assertHeld($account);
    }
}
