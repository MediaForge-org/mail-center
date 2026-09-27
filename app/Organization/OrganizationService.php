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

    /** Local move only: never issues IMAP MOVE/COPY/DELETE/EXPUNGE or any structural command. */
    public function moveMessage(int $userId, int $messageId, int $folderId): array
    {
        return DB::transaction(function () use ($userId, $messageId, $folderId) {
            $this->lockVersion($userId);
            $folder = DB::table('folders')->where('id', $folderId)->where('user_id', $userId)->lockForUpdate()->first();
            abort_if($folder === null, 404);
            $message = DB::table('messages')->where('id', $messageId)->where('user_id', $userId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($message === null, 404);
            if ((int) $message->folder_id !== $folderId) {
                DB::table('messages')->where('id', $messageId)->update([
                    'folder_id' => $folderId, 'local_revision' => $message->local_revision + 1,
                    'local_updated_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit($userId, 'messages.moved', 'message', $messageId, [
                    'from_folder_id' => $message->folder_id, 'to_folder_id' => $folderId,
                ]);
            }

            return ['id' => $messageId, 'folder_id' => $folderId];
        });
    }

    /**
     * Explicit thread-level move, requested only via the reader's "Move conversation" action.
     * Expands to the user's own accessible message ids on the server; never crosses accounts.
     */
    public function moveConversation(int $userId, int $messageId, int $folderId): array
    {
        return DB::transaction(function () use ($userId, $messageId, $folderId) {
            $this->lockVersion($userId);
            $folder = DB::table('folders')->where('id', $folderId)->where('user_id', $userId)->lockForUpdate()->first();
            abort_if($folder === null, 404);
            $selected = DB::table('messages')->where('id', $messageId)->where('user_id', $userId)->whereNull('deleted_at')->first();
            abort_if($selected === null, 404);
            $scope = DB::table('messages')->where('user_id', $userId)->where('mail_account_id', $selected->mail_account_id)->whereNull('deleted_at');
            $selected->thread_id === null ? $scope->where('id', $messageId) : $scope->where('thread_id', $selected->thread_id);
            $rows = $scope->lockForUpdate()->orderBy('id')->get(['id', 'folder_id']);
            $movedIds = $rows->filter(fn ($row) => (int) $row->folder_id !== $folderId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($movedIds !== []) {
                DB::table('messages')->whereIn('id', $movedIds)->update([
                    'folder_id' => $folderId, 'local_revision' => DB::raw('local_revision + 1'),
                    'local_updated_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit($userId, 'messages.moved', 'message', $messageId, [
                'conversation' => true, 'to_folder_id' => $folderId,
                'moved_count' => count($movedIds), 'moved_sample' => array_slice($movedIds, 0, 20),
            ]);

            return ['moved_count' => count($movedIds), 'folder_id' => $folderId];
        });
    }

    public function createFolder(int $userId, string $name): array
    {
        $name = $this->validatedFolderName($name);

        return DB::transaction(function () use ($userId, $name) {
            $this->lockVersion($userId);
            $this->assertFolderNameAvailable($userId, $name, null);
            $position = (int) (DB::table('folders')->where('user_id', $userId)->max('position') ?? -1) + 1;
            $id = DB::table('folders')->insertGetId([
                'user_id' => $userId, 'name' => $name, 'system_role' => null, 'position' => $position,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($userId, 'folder.created', 'folder', $id, ['name' => $name]);

            return ['id' => $id, 'name' => $name, 'system_role' => null, 'position' => $position];
        });
    }

    public function renameFolder(int $userId, int $id, string $name): array
    {
        $name = $this->validatedFolderName($name);

        return DB::transaction(function () use ($userId, $id, $name) {
            $this->lockVersion($userId);
            $folder = DB::table('folders')->where('id', $id)->where('user_id', $userId)->lockForUpdate()->first();
            abort_if($folder === null, 404);
            if ($folder->name !== $name) {
                $this->assertFolderNameAvailable($userId, $name, $id);
                DB::table('folders')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
                $this->audit($userId, 'folder.renamed', 'folder', $id, ['from' => $folder->name, 'to' => $name]);
            }

            return ['id' => $id, 'name' => $name, 'system_role' => $folder->system_role, 'position' => $folder->position];
        });
    }

    /** @param  array<int, int>  $orderedIds */
    public function reorderFolders(int $userId, array $orderedIds): void
    {
        $orderedIds = array_map('intval', $orderedIds);
        abort_if(count($orderedIds) !== count(array_unique($orderedIds)), 422, 'Duplicate folder id in order.');

        DB::transaction(function () use ($userId, $orderedIds) {
            $this->lockVersion($userId);
            $owned = DB::table('folders')->where('user_id', $userId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            sort($owned);
            $given = $orderedIds;
            sort($given);
            abort_if($owned !== $given, 422, 'Folder order must contain exactly the user\'s existing folders.');
            foreach ($orderedIds as $position => $id) {
                DB::table('folders')->where('id', $id)->where('user_id', $userId)->update(['position' => $position, 'updated_at' => now()]);
            }
            $this->audit($userId, 'folder.reordered', 'user', $userId, ['count' => count($orderedIds)]);
        });
    }

    /** Custom folders only; system folders can never be deleted. Emails are never deleted here. */
    public function deleteFolder(int $userId, int $id): array
    {
        return DB::transaction(function () use ($userId, $id) {
            $this->lockVersion($userId);
            $folder = DB::table('folders')->where('id', $id)->where('user_id', $userId)->lockForUpdate()->first();
            abort_if($folder === null, 404);
            abort_if($folder->system_role !== null, 422, 'System folders cannot be deleted.');
            $inboxId = SystemFolders::idFor($userId, 'inbox');
            $movedIds = DB::table('messages')->where('user_id', $userId)->where('folder_id', $id)
                ->lockForUpdate()->pluck('id')->map(fn ($msgId) => (int) $msgId)->all();
            if ($movedIds !== []) {
                DB::table('messages')->whereIn('id', $movedIds)->update([
                    'folder_id' => $inboxId, 'local_revision' => DB::raw('local_revision + 1'),
                    'local_updated_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('folders')->where('id', $id)->delete();
            $this->audit($userId, 'folder.deleted', 'folder', $id, [
                'moved_count' => count($movedIds), 'moved_sample' => array_slice($movedIds, 0, 20),
            ]);

            return ['id' => $id, 'moved_count' => count($movedIds), 'inbox_folder_id' => $inboxId];
        });
    }

    private function validatedFolderName(string $name): string
    {
        $trimmed = trim($name);
        abort_if($trimmed === '', 422, 'Folder name cannot be empty.');
        abort_if(mb_strlen($trimmed) > 80, 422, 'Folder name is too long.');
        abort_if(preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1, 422, 'Folder name contains invalid characters.');

        return $trimmed;
    }

    private function assertFolderNameAvailable(int $userId, string $name, ?int $excludeId): void
    {
        $exists = DB::table('folders')->where('user_id', $userId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();
        abort_if($exists, 422, 'A folder with this name already exists.');
    }

    private function audit(int $userId, string $action, string $subjectType, int $subjectId, array $context = []): void
    {
        DB::table('audit_events')->insert([
            'user_id' => $userId, 'actor_type' => 'user', 'action' => $action,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'context' => json_encode($context), 'created_at' => now(),
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
