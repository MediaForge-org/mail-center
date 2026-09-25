<?php

namespace App\Sync;

use App\Models\MailAccount;

interface AccountSyncDriver
{
    /** @return array{remaining:bool, new:int, linked:int, removed:int, failures:int, partial:int} */
    public function sync(MailAccount $account): array;
}
