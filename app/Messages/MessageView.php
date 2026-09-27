<?php

namespace App\Messages;

use App\Organization\SystemFolders;
use Illuminate\Database\Query\Builder;

enum MessageView: string
{
    case All = 'all';
    case Inbox = 'inbox';
    case Unread = 'unread';
    case Sent = 'sent';
    case Archive = 'archive';

    public function cursorScope(): string
    {
        return match ($this) {
            self::All => 'all-mail:v1', // Preserve M3.1 All Mail cursors.
            self::Inbox => 'system-view:inbox:v1',
            self::Unread => 'system-view:unread:v1',
            self::Sent, self::Archive => 'system-view:'.$this->value.':v1',
        };
    }

    public function apply(Builder $query, int $userId): void
    {
        match ($this) {
            self::All => null,
            // Remote Sent-folder membership is authoritative; never infer Sent from the From address.
            self::Sent => $query->whereExists(fn (Builder $exists) => $exists->selectRaw('1')->from('message_locations')
                ->join('remote_folders', 'remote_folders.id', '=', 'message_locations.remote_folder_id')
                ->whereColumn('message_locations.message_id', 'messages.id')
                ->where('remote_folders.role', 'sent')->whereNull('remote_folders.removed_at')
                ->whereNull('message_locations.removed_at')),
            self::Archive => $query->where('messages.folder_id', SystemFolders::idFor($userId, $this->value)),
            self::Inbox => $query->where('messages.folder_id', SystemFolders::idFor($userId, 'inbox'))
                ->where('messages.is_done', false)
                ->where('messages.remote_status', '<>', 'removed'),
            self::Unread => $query->where('messages.is_read', false)
                ->where('messages.remote_status', '<>', 'removed'),
        };
    }
}
