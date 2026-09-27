<?php

namespace App\Connectors\Imap;

use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;

class PinnedImapStream extends ImapStream
{
    public function __construct(private readonly string $address, private readonly string $hostname) {}

    /** @return resource */
    public function socket(): mixed
    {
        if (! is_resource($this->stream)) {
            throw new \RuntimeException('IMAP stream is closed.');
        }

        return $this->stream;
    }

    public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
    {
        $options['ssl'] = array_merge($options['ssl'] ?? [], [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->hostname,
            'SNI_enabled' => true,
            'SNI_server_name' => $this->hostname,
        ]);

        return parent::open($transport, str_contains($this->address, ':') ? "[{$this->address}]" : $this->address, $port, $timeout, $options);
    }
}
