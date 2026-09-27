<?php

namespace App\Sync\Idle;

use App\Models\MailAccount;
use App\Models\RemoteFolder;

interface IdleClient
{
    /** Connect, EXAMINE and enter IDLE. False means capability fallback. */
    public function open(MailAccount $account, RemoteFolder $folder): bool;

    /** @return resource */
    public function socket(): mixed;

    /** Consume one ready notification, leave/re-enter IDLE safely; true means mailbox changed. */
    public function changed(): bool;

    public function renew(): void;

    public function close(): void;
}
