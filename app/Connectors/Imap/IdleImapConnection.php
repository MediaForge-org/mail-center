<?php

namespace App\Connectors\Imap;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use RuntimeException;

/** Uses the existing parser, TLS transport and command encoder; only splits IDLE for multiplexing. */
class IdleImapConnection extends ImapConnection
{
    private bool $pending = false;

    public function beginIdle(): void
    {
        $this->stream->setTimeout(2);
        $this->send('IDLE');
        $this->assertNextResponse(function (Response $response) {
            $this->observe($response);

            return $response instanceof ContinuationResponse || $response instanceof TaggedResponse;
        }, fn ($response) => $response instanceof ContinuationResponse,
            fn () => new RuntimeException('IDLE was refused.'));
    }

    public function notification(): bool
    {
        $response = $this->nextReply();
        if ($response instanceof TaggedResponse) {
            throw new RuntimeException('Server ended IDLE unexpectedly.');
        }
        if ($response instanceof Response) {
            $this->observe($response);
        }
        $changed = $this->pending;
        $this->pending = false;

        return $changed;
    }

    public function endIdle(): void
    {
        $this->write('DONE');
        $this->assertNextResponse(function (Response $response) {
            $this->observe($response);

            return $response instanceof TaggedResponse;
        }, fn (TaggedResponse $response) => $response->successful(),
            fn () => new RuntimeException('IDLE did not finish.'));
    }

    private function observe(Response $response): void
    {
        if (! $response instanceof UntaggedResponse) {
            return;
        }
        $parts = $response->toArray();
        if (strtoupper((string) ($parts[1] ?? '')) === 'BYE') {
            throw new RuntimeException('IDLE disconnected.');
        }
        if (is_string($parts[2] ?? null) && in_array(strtoupper($parts[2]), ['EXISTS', 'EXPUNGE', 'FETCH'], true)) {
            $this->pending = true;
        }
    }
}
