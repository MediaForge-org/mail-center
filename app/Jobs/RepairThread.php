<?php

namespace App\Jobs;

use App\Conversations\Threader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RepairThread implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(Threader $threader): void
    {
        $threader->assign($this->messageId);
    }
}
