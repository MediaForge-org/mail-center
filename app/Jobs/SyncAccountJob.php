<?php

namespace App\Jobs;

use App\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Durable database generations coalesce requests; PostgreSQL fences all remote writers. */
class SyncAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 600;

    public function __construct(public readonly int $accountId, public readonly string $trigger = 'scheduled')
    {
        $this->onConnection('mail_sync');
        $this->onQueue(in_array($trigger, ['manual', 'idle'], true) ? 'sync-high' : 'sync');
    }

    public function handle(SyncRunner $runner): void
    {
        $result = $runner->run($this->accountId, $this->trigger, true);
        if ($result === 'locked' && $this->job !== null) {
            $this->release(1);
        }
    }
}
