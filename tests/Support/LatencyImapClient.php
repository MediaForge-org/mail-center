<?php

namespace Tests\Support;

use App\Accounts\Credentials\Secret;
use App\Models\MailAccount;
use Illuminate\Support\Facades\DB;

/** Cross-process deterministic remote mailbox. Only the isolated latency harness binds this. */
class LatencyImapClient extends FakeImapClient
{
    private int $accountId;

    private string $folder = 'INBOX';

    public function connect(MailAccount $account, Secret $secret): void
    {
        $this->accountId = $account->id;
        $row = DB::table('fixture_m311')->where('account_id', $this->accountId)->first();
        DB::table('fixture_m311')->where('account_id', $this->accountId)->update(['connect_started' => microtime(true)]);
        usleep((int) $row->connect_ms * 1000);
        $this->reload();
        parent::connect($account, $secret);
    }

    private function reload(): void
    {
        $this->mailboxes = json_decode(DB::table('fixture_m311')->where('account_id', $this->accountId)->value('mailboxes'), true);
    }

    public function examine(string $rawName): array
    {
        $this->reload();
        $this->folder = $rawName;

        return parent::examine($rawName);
    }

    public function fetch(int $uid): ?array
    {
        $row = DB::table('fixture_m311')->where('account_id', $this->accountId)->first();
        if ($this->folder === 'Sent' && $uid <= $row->slow_before) {
            usleep(100000);
        }

        return parent::fetch($uid);
    }
}
