<?php

namespace App\Sync;

use App\Models\MailAccount;
use Illuminate\Support\Facades\DB;

/**
 * Session-level PostgreSQL advisory lock on a dedicated connection. Redis job uniqueness is only
 * an optimization; this lock is what prevents two live writers for one mailbox.
 */
class AccountSyncLock
{
    private const NAMESPACE = 0x4D43; // "MC": network account lock namespace

    /** @var array<int, true> */
    private array $held = [];

    public function acquire(MailAccount $account): bool
    {
        if (isset($this->held[$account->id])) {
            return false;
        }
        $row = DB::connection('sync_lock')->selectOne(
            'SELECT pg_try_advisory_lock(?, ?) AS locked', [self::NAMESPACE, $account->id]
        );
        if (! $row->locked) {
            return false;
        }

        return $this->held[$account->id] = true;
    }

    /** Verifies that this database session still owns the lock (a silent reconnect would not). */
    public function assertHeld(MailAccount $account): void
    {
        $row = DB::connection('sync_lock')->selectOne(
            "SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()
             AND classid = ? AND objid = ? AND objsubid = 2 AND granted",
            [self::NAMESPACE, $account->id]
        );
        if (! isset($this->held[$account->id]) || (int) $row->n < 1) {
            throw new SyncAborted('Account synchronization lock was lost.');
        }
    }

    public function release(MailAccount $account): void
    {
        if (! isset($this->held[$account->id])) {
            return;
        }
        unset($this->held[$account->id]);
        try {
            DB::connection('sync_lock')->select('SELECT pg_advisory_unlock(?, ?)', [self::NAMESPACE, $account->id]);
        } catch (\Throwable) {
            DB::purge('sync_lock'); // closing the session releases every advisory lock it owns
        }
    }
}
