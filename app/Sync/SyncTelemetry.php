<?php

namespace App\Sync;

/** Safe phase timestamps only: never protocol text, addresses, subjects or credentials. */
class SyncTelemetry
{
    private array $stages = [];

    public function start(): void
    {
        $this->stages = [];
        $this->mark('worker_started');
    }

    public function mark(string $stage): void
    {
        $this->stages[$stage] ??= microtime(true);
    }

    public function all(): array
    {
        return $this->stages;
    }
}
