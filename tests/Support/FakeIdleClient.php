<?php

namespace Tests\Support;

use App\Models\MailAccount;
use App\Models\RemoteFolder;
use App\Sync\Idle\IdleClient;
use Throwable;

/** Socket readiness exercises the real watcher select loop without provider credentials. */
class FakeIdleClient implements IdleClient
{
    public bool $supported = true;

    public ?Throwable $failure = null;

    public bool $closed = false;

    public ?string $folder = null;

    private array $pair;

    public function __construct()
    {
        $this->pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    }

    public function open(MailAccount $account, RemoteFolder $folder): bool
    {
        $this->folder = $folder->role;
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->supported;
    }

    public function socket(): mixed
    {
        return $this->pair[0];
    }

    public function notify(): void
    {
        fwrite($this->pair[1], 'event');
    }

    public function changed(): bool
    {
        fread($this->pair[0], 4096);
        if ($this->failure) {
            throw $this->failure;
        }

        return true;
    }

    public function renew(): void {}

    public function close(): void
    {
        $this->closed = true;
        foreach ($this->pair as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
