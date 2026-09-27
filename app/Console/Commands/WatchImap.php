<?php

namespace App\Console\Commands;

use App\Sync\Idle\IdleWatcher;
use Illuminate\Console\Command;

class WatchImap extends Command
{
    protected $signature = 'sync:watch {--connections=32 : Maximum open IDLE connections in this process} {--seconds=0 : Optional bounded operator/test run}';

    protected $description = 'Watch enabled Inbox and Sent via leased, multiplexed IMAP IDLE connections';

    public function handle(IdleWatcher $watcher): int
    {
        $capacity = max(1, min(128, (int) $this->option('connections')));
        $limit = max(0, (int) $this->option('seconds'));
        $stopping = false;
        // Plain pcntl handlers on purpose: the framework's trap() layers on Symfony's signal dispatcher,
        // whose default handler exits immediately (143) and would skip the lease-releasing finally block.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$stopping) {
                    $stopping = true;
                });
            }
        }
        $start = microtime(true);
        $next = 0.0;
        try {
            while (! $stopping && ($limit === 0 || microtime(true) - $start < $limit)) {
                if (microtime(true) >= $next) {
                    $watcher->reconcile($capacity);
                    $next = microtime(true) + 2;
                }
                $watcher->wait();
            }
        } finally {
            $watcher->close();
        }

        return self::SUCCESS;
    }
}
