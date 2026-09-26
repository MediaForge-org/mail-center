<?php

namespace App\Jobs;

use App\Writeback\SeenWriteback;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PushRemoteFlagChangesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $accountId)
    {
        $this->onConnection('writeback')->onQueue('writeback');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('imap-account:'.$this->accountId))->shared()->releaseAfter(30)->expireAfter(900)];
    }

    public function handle(SeenWriteback $runner): void
    {
        $runner->run($this->accountId);
    }
}
