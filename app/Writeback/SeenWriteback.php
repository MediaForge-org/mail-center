<?php

namespace App\Writeback;

use App\Accounts\Credentials\CredentialVault;
use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Ingestion\MessageIngestor;
use App\Models\MailAccount;
use App\Organization\OrganizationService;
use App\Sync\AccountSyncLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SeenWriteback
{
    public function __construct(
        private readonly AccountSyncLock $lock,
        private readonly ImapClient $client,
        private readonly CredentialVault $vault,
        private readonly OrganizationService $organization,
    ) {}

    public function run(int $accountId): void
    {
        $account = MailAccount::find($accountId);
        if (! $this->eligible($account) || ! $this->lock->acquire($account)) {
            return;
        }
        $claimed = collect();
        $results = [];
        $error = null;
        try {
            $this->lock->assertHeld($account);
            $account->refresh();
            if (! $this->eligible($account)) {
                return;
            }
            $claimed = DB::transaction(function () use ($account) {
                $rows = DB::table('remote_flag_changes')->where('mail_account_id', $account->id)->where('flag', '\\Seen')
                    ->where(function ($q) {
                        $q->where(fn ($q) => $q->where('status', 'pending')->where('next_attempt_at', '<=', now()))
                            ->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_expires_at', '<=', now()));
                    })->orderBy('id')->limit(100)->lockForUpdate()->get();
                foreach ($rows as $row) {
                    $row->lease_token = (string) Str::uuid();
                    $row->attempts++;
                    DB::table('remote_flag_changes')->where('id', $row->id)->update([
                        'status' => 'processing', 'lease_token' => $row->lease_token,
                        'lease_expires_at' => now()->addSeconds(150), 'attempts' => $row->attempts,
                    ]);
                }

                return $rows;
            });
            if ($claimed->isEmpty()) {
                return;
            }
            $groups = [];
            foreach ($claimed as $row) {
                $results[$row->id] = ['completed' => [], 'error' => null];
                if (! $this->current($account, $row)) {
                    continue;
                }
                $locations = DB::table('message_locations')->join('remote_folders', 'remote_folders.id', '=', 'message_locations.remote_folder_id')
                    ->where('message_locations.message_id', $row->message_id)->whereNull('message_locations.removed_at')
                    ->where('remote_folders.sync_enabled', true)->whereNull('remote_folders.removed_at')
                    ->select('message_locations.*', 'remote_folders.raw_name')->get();
                if ($locations->isEmpty()) {
                    $removed = DB::table('messages')->where('id', $row->message_id)->value('remote_status') === 'removed';
                    $results[$row->id]['error'] = $removed ? 'no_target' : 'awaiting_locations';
                }
                foreach ($locations as $location) {
                    $groups[$location->remote_folder_id.':'.$location->uidvalidity.':'.(int) $row->desired][] = [$row, $location];
                }
            }
            $deadline = microtime(true) + 90;
            if ($groups !== []) {
                $this->client->connect($account, $this->vault->forAccount($account));
            }
            foreach ($groups as $items) {
                foreach (array_chunk($items, 100) as $batch) {
                    if (microtime(true) >= $deadline) {
                        $error = 'work_budget';
                        break 2;
                    }
                    $this->lock->assertHeld($account);
                    $account->refresh();
                    if (! $this->eligible($account)) {
                        $error = 'account_unavailable';
                        break 2;
                    }
                    $batch = array_values(array_filter($batch, fn ($pair) => $this->current($account, $pair[0])));
                    if ($batch === []) {
                        continue;
                    }
                    [$first, $folder] = $batch[0];
                    $identity = $this->client->selectForSeen($folder->raw_name);
                    $this->lock->assertHeld($account);
                    if ($identity['uidvalidity'] !== (int) $folder->uidvalidity) {
                        foreach ($batch as [$row]) {
                            $results[$row->id]['error'] = 'uidvalidity_changed';
                        }
                        DB::table('mail_accounts')->where('id', $accountId)->update(['next_sync_at' => now()]);

                        continue;
                    }
                    if (! $identity['writable_seen']) {
                        throw new ImapFailure(ImapFailure::FOLDER, 'Seen flag is not writable.');
                    }
                    // Recheck after SELECT and immediately before STORE.
                    $account->refresh();
                    if (! $this->eligible($account)) {
                        $error = 'account_unavailable';
                        break 2;
                    }
                    $batch = array_values(array_filter($batch, fn ($pair) => $this->current($account, $pair[0])
                        && DB::table('message_locations')->where('id', $pair[1]->id)->whereNull('removed_at')->exists()));
                    if ($batch === []) {
                        continue;
                    }
                    $uids = array_values(array_unique(array_map(fn ($pair) => (int) $pair[1]->uid, $batch)));
                    $this->lock->assertHeld($account);
                    $this->client->storeSeen($uids, (bool) $first->desired);
                    $this->lock->assertHeld($account);
                    $verified = $this->client->flagsForUids($uids);
                    $this->lock->assertHeld($account);
                    foreach ($batch as [$row, $location]) {
                        if (! array_key_exists($location->uid, $verified) || in_array('\\Seen', $verified[$location->uid], true) !== $row->desired) {
                            $results[$row->id]['error'] = 'verification_failed';

                            continue;
                        }
                        DB::table('message_locations')->where('id', $location->id)->whereNull('removed_at')->update(['flags' => json_encode($verified[$location->uid])]);
                        app(MessageIngestor::class)->refreshRemoteSummary($row->message_id);
                        $results[$row->id]['completed'][] = $location->id;
                        DB::table('remote_flag_changes')->where('id', $row->id)->where('lease_token', $row->lease_token)->update([
                            'completed_location_ids' => json_encode($results[$row->id]['completed']),
                        ]);
                    }
                }
            }
        } catch (ImapFailure $failure) {
            $error = $failure->category;
            if ($error === ImapFailure::AUTH) {
                DB::table('mail_accounts')->where('id', $accountId)->update(['sync_status' => 'auth_failed', 'next_sync_at' => null, 'seen_writeback_error' => $error]);
            }
        } catch (Throwable) {
            $error = 'writeback_failed';
        } finally {
            try {
                $this->client->close();
            } catch (Throwable) {
            }
            try {
                foreach ($claimed as $row) {
                    $result = $results[$row->id] ?? ['completed' => [], 'error' => null];
                    $this->finish($row, $result['completed'], $result['error'] ?? $error);
                }
                if (! DB::table('remote_flag_changes')->where('mail_account_id', $accountId)->whereIn('status', ['pending', 'processing', 'failed'])->whereNotNull('last_error')->exists()) {
                    DB::table('mail_accounts')->where('id', $accountId)->where('seen_writeback_error', '<>', 'no_target')->update(['seen_writeback_error' => null]);
                }
            } finally {
                $this->lock->release($account);
            }
        }
    }

    private function eligible(?MailAccount $account): bool
    {
        return $account !== null && ! $account->trashed() && $account->enabled && $account->write_back_seen
            && $account->sync_status !== 'auth_failed' && $account->seen_writeback_error !== 'auth_failed';
    }

    private function current(MailAccount $account, object $row): bool
    {
        return $account->write_back_seen && $account->seen_mirror_generation === $row->mirror_generation
            && DB::table('messages')->where('id', $row->message_id)->whereNull('deleted_at')
                ->where('read_intent_generation', $row->generation)->exists();
    }

    private function finish(object $row, array $completed, ?string $error): void
    {
        DB::transaction(function () use ($row, $completed, $error) {
            $reference = DB::table('mail_accounts')->where('id', $row->mail_account_id)->first();
            $this->organization->lockVersion($reference->user_id);
            $account = MailAccount::withTrashed()->lockForUpdate()->findOrFail($row->mail_account_id);
            $message = DB::table('messages')->where('id', $row->message_id)->lockForUpdate()->first();
            $intent = DB::table('remote_flag_changes')->where('id', $row->id)->lockForUpdate()->first();
            if ($intent->status !== 'processing' || $intent->lease_token !== $row->lease_token) {
                return;
            }
            $status = 'done';
            if ($account->trashed() || ! $this->current($account, $row)) {
                $status = 'superseded';
            } else {
                $active = DB::table('message_locations')->where('message_id', $row->message_id)->whereNull('removed_at')->pluck('id')->all();
                if ($active === [] || array_diff($active, $completed) !== []) {
                    $error ??= 'awaiting_locations';
                }
                if ($error === 'no_target') {
                    $status = 'superseded';
                } elseif ($error !== null) {
                    $status = $row->attempts >= 10 && ! in_array($error, ['awaiting_locations', 'work_budget', 'account_unavailable'], true) ? 'failed' : 'pending';
                }
                if ($status === 'done') {
                    $this->organization->acknowledgeSeen($row->message_id, $row->generation, $row->mirror_generation, $row->desired);
                }
            }
            DB::table('remote_flag_changes')->where('id', $row->id)->update([
                'status' => $status, 'last_error' => $error, 'lease_expires_at' => null, 'lease_token' => null,
                'processed_at' => in_array($status, ['done', 'superseded'], true) ? now() : null,
                'next_attempt_at' => $status === 'pending' ? now()->addSeconds(min(3600, 60 * (2 ** min($row->attempts - 1, 6)))) : null,
                'attempts' => in_array($error, ['awaiting_locations', 'work_budget', 'account_unavailable'], true) ? max(0, $row->attempts - 1) : $row->attempts,
                'completed_location_ids' => json_encode($completed),
            ]);
            if ($error !== null && $status !== 'done') {
                $account->update(['seen_writeback_error' => $error]);
            }
        });
    }
}
