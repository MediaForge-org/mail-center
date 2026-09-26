<?php

namespace App\Organization;

use App\Jobs\PushRemoteFlagChangesJob;
use App\Models\MailAccount;
use Illuminate\Support\Facades\DB;

class OrganizationService
{
    public function lockVersion(int $userId): void
    {
        DB::table('user_change_versions')->insertOrIgnore(['user_id' => $userId, 'version' => 0]);
        DB::table('user_change_versions')->where('user_id', $userId)->lockForUpdate()->first();
    }

    private function changed(int $userId): void
    {
        DB::table('user_change_versions')->where('user_id', $userId)->increment('version');
    }

    public function setRead(int $userId, int $id, bool $desired): array
    {
        return DB::transaction(function () use ($userId, $id, $desired) {
            $this->lockVersion($userId);
            $reference = DB::table('messages')->where('id', $id)->where('user_id', $userId)->whereNull('deleted_at')->first();
            abort_if($reference === null, 404);
            $account = MailAccount::where('user_id', $userId)->lockForUpdate()->findOrFail($reference->mail_account_id);
            $message = DB::table('messages')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($message === null, 404);
            $failed = DB::table('remote_flag_changes')->where('message_id', $id)->where('flag', '\\Seen')->where('status', 'failed')->exists();
            if ($message->is_read !== $desired || ($account->write_back_seen && ($message->seen_mirror_generation !== $account->seen_mirror_generation || $failed))) {
                $generation = $message->read_intent_generation + 1;
                DB::table('messages')->where('id', $id)->update([
                    'is_read' => $desired, 'local_revision' => $message->local_revision + 1,
                    'local_updated_at' => now(), 'updated_at' => now(), 'read_intent_generation' => $generation,
                    'seen_mirror_generation' => $account->seen_mirror_generation,
                ]);
                DB::table('remote_flag_changes')->where('message_id', $id)->where('flag', '\\Seen')
                    ->whereIn('status', ['pending', 'failed'])->update(['status' => 'superseded', 'processed_at' => now()]);
                if ($account->write_back_seen) {
                    DB::table('remote_flag_changes')->insert([
                        'mail_account_id' => $account->id, 'message_id' => $id, 'flag' => '\\Seen',
                        'desired' => $desired, 'generation' => $generation, 'mirror_generation' => $account->seen_mirror_generation,
                        'created_at' => now(), 'next_attempt_at' => now(),
                    ]);
                    $accountId = $account->id;
                    DB::afterCommit(static function () use ($accountId) {
                        try {
                            PushRemoteFlagChangesJob::dispatch($accountId)->afterCommit();
                        } catch (\Throwable) {
                            // Local state and durable intent already committed; the sweeper recovers dispatch loss.
                        }
                    });
                }
                $this->changed($userId);
            }

            return ['id' => $id, 'is_read' => $desired, 'read_writeback' => DB::table('remote_flag_changes')->where('message_id', $id)
                ->whereIn('status', ['pending', 'processing', 'failed'])->orderByDesc('generation')->value('status')];
        });
    }

    public function setSeenMirroring(int $userId, int $accountId, bool $enabled): void
    {
        DB::transaction(function () use ($userId, $accountId, $enabled) {
            $this->lockVersion($userId);
            $account = MailAccount::where('user_id', $userId)->lockForUpdate()->findOrFail($accountId);
            if ($account->write_back_seen === $enabled) {
                return;
            }
            $account->update([
                'write_back_seen' => $enabled, 'seen_mirror_generation' => $account->seen_mirror_generation + 1,
                'seen_writeback_error' => null,
                'next_sync_at' => $account->enabled && $account->sync_enabled && $account->sync_status !== 'auth_failed' ? now() : $account->next_sync_at,
            ]);
            DB::table('remote_flag_changes')->where('mail_account_id', $accountId)->where('flag', '\\Seen')
                ->whereIn('status', ['pending', 'failed'])->update(['status' => 'superseded', 'processed_at' => now(), 'last_error' => 'mirroring_changed']);
            $this->changed($userId);
        });
    }

    /** Called with account/message locks held after all active locations were verified. */
    public function acknowledgeSeen(int $messageId, int $generation, int $mirrorGeneration, bool $desired): void
    {
        DB::table('messages')->where('id', $messageId)->where('read_intent_generation', $generation)->update([
            'seen_mirror_generation' => $mirrorGeneration,
            'seen_reconciled_remote' => $desired,
            'seen_reconciled_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /** Called after FETCH, with generations captured before network IO. No partial aggregate is applied. */
    public function observeSeen(int $locationId, array $flags, int $mirrorGeneration, int $readGeneration): void
    {
        $location = DB::table('message_locations')->where('id', $locationId)->first();
        if ($location === null) {
            return;
        }
        $reference = DB::table('messages')->where('id', $location->message_id)->first();
        if ($reference === null) {
            return;
        }
        DB::transaction(function () use ($reference, $locationId, $flags, $mirrorGeneration, $readGeneration) {
            $this->lockVersion($reference->user_id);
            $account = MailAccount::lockForUpdate()->find($reference->mail_account_id);
            if ($account === null) {
                return;
            }
            $message = DB::table('messages')->where('id', $reference->id)->lockForUpdate()->first();
            DB::table('message_locations')->where('id', $locationId)->whereNull('removed_at')->update([
                'flags' => json_encode(array_values($flags)),
                'seen_observed_mirror_generation' => $mirrorGeneration,
                'seen_observed_read_generation' => $readGeneration, 'seen_observed_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
            $locations = DB::table('message_locations')->where('message_id', $message->id)->whereNull('removed_at')->get();
            if ($locations->isEmpty()) {
                return;
            }
            $seen = $locations->contains(fn ($row) => in_array('\\Seen', json_decode($row->flags, true), true));
            DB::table('messages')->where('id', $message->id)->update(['remote_seen' => $seen]);
            if ($account->seen_mirror_generation !== $mirrorGeneration || $message->read_intent_generation !== $readGeneration) {
                return;
            }
            foreach ($locations as $row) {
                if ($row->seen_observed_mirror_generation !== $mirrorGeneration || $row->seen_observed_read_generation !== $readGeneration
                    || $row->seen_observed_at === null || ($message->seen_mirror_generation === $mirrorGeneration && $message->seen_reconciled_at !== null && $row->seen_observed_at <= $message->seen_reconciled_at)) {
                    return;
                }
            }
            $blocked = DB::table('remote_flag_changes')->where('message_id', $message->id)->where('flag', '\\Seen')
                ->whereIn('status', ['pending', 'processing', 'failed'])->exists();
            $update = ['seen_reconciled_remote' => $seen, 'seen_reconciled_at' => now()->format('Y-m-d H:i:s.uP')];
            if ($account->write_back_seen && ! $blocked && $message->deleted_at === null
                && ($message->seen_mirror_generation !== $mirrorGeneration || $message->seen_reconciled_remote !== $seen)) {
                $update['seen_mirror_generation'] = $mirrorGeneration;
                if ($message->is_read !== $seen) {
                    $update += ['is_read' => $seen, 'local_revision' => $message->local_revision + 1, 'local_updated_at' => now(), 'updated_at' => now()];
                    $this->changed($reference->user_id);
                }
            }
            DB::table('messages')->where('id', $message->id)->update($update);
        });
    }
}
