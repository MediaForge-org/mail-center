<?php

namespace App\Sync\Idle;

use App\Accounts\Credentials\CredentialVault;
use App\Connectors\Imap\DestinationPolicy;
use App\Connectors\Imap\IdleImapConnection;
use App\Connectors\Imap\ImapFailure;
use App\Connectors\Imap\LibraryImapClient;
use App\Connectors\Imap\PinnedImapStream;
use App\Models\MailAccount;
use App\Models\RemoteFolder;
use Throwable;

class LibraryIdleClient implements IdleClient
{
    private LibraryImapClient $client;

    private IdleImapConnection $connection;

    private PinnedImapStream $stream;

    public function __construct(private readonly CredentialVault $vault, DestinationPolicy $destinations, ?\Closure $streams = null)
    {
        $this->client = new LibraryImapClient($destinations, function (string $address, string $host) use ($streams) {
            $this->stream = $streams ? $streams($address, $host) : new PinnedImapStream($address, $host);

            return $this->connection = new IdleImapConnection($this->stream);
        }, 2);
    }

    public function open(MailAccount $account, RemoteFolder $folder): bool
    {
        $this->client->connect($account, $this->vault->forAccount($account));

        return $this->safe(function () use ($folder) {
            $caps = array_map(fn ($value) => strtoupper((string) $value), $this->connection->capability()->toArray());
            if (! in_array('IDLE', $caps, true)) {
                return false;
            }
            $this->client->examine($folder->raw_name);
            $this->connection->beginIdle();

            return true;
        });
    }

    public function socket(): mixed
    {
        return $this->stream->socket();
    }

    public function changed(): bool
    {
        return $this->safe(function () {
            $changed = $this->connection->notification();
            if ($changed) {
                $this->connection->endIdle();
                $this->connection->beginIdle();
            }

            return $changed;
        });
    }

    public function renew(): void
    {
        $this->safe(function () {
            $this->connection->endIdle();
            $this->connection->beginIdle();
        });
    }

    public function close(): void
    {
        // Disconnect is safe even after a partial response; never CLOSE/EXPUNGE.
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
        $this->client->close();
    }

    private function safe(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (ImapFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new ImapFailure(ImapFailure::TRANSIENT, ImapFailure::safeMessage(ImapFailure::TRANSIENT));
        }
    }
}
