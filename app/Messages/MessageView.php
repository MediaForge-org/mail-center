<?php

namespace App\Messages;

use App\Organization\SystemFolders;
use Illuminate\Database\Query\Builder;

enum MessageView: string
{
    case All = 'all';
    case Inbox = 'inbox';
    case Unread = 'unread';

    public function cursorScope(): string
    {
        return match ($this) {
            self::All => 'all-mail:v1', // Preserve M3.1 All Mail cursors.
            self::Inbox => 'system-view:inbox:v1',
            self::Unread => 'system-view:unread:v1',
        };
    }

    public function apply(Builder $query, int $userId): void
    {
        match ($this) {
            self::All => null,
            self::Inbox => $query->where('messages.folder_id', SystemFolders::idFor($userId, 'inbox'))
                ->where('messages.is_done', false)
                ->where('messages.remote_status', '<>', 'removed'),
            self::Unread => $query->where('messages.is_read', false)
                ->where('messages.remote_status', '<>', 'removed'),
        };
    }
}
