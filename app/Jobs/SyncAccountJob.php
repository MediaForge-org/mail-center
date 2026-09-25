<?php

namespace App\Jobs;

use App\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One job per account. No Laravel-level retries: the run is checkpointed and idempotent, and the
 * scheduler re-dispatches according to the persisted backoff. Redis uniqueness is an optimization;
 * the PostgreSQL advisory lock in SyncRunner is the actual writer guard.
 */
class SyncAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $accountId, public readonly string $trigger = 'scheduled')
    {
        $this->onConnection('mail_sync');
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(SyncRunner $runner): void
    {
        $runner->run($this->accountId, $this->trigger);
    }
}
