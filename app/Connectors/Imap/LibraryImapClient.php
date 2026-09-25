<?php

namespace App\Connectors\Imap;

use App\Accounts\Credentials\Secret;
use App\Models\MailAccount;
use Closure;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Mailbox;
use RuntimeException;
use Throwable;

class LibraryImapClient implements ImapClient
{
    private ?Mailbox $mailbox = null;

    /** @param  Closure(string, string): ConnectionInterface|null  $connections  test seam: (address, hostname) => connection */
    public function __construct(private readonly DestinationPolicy $destinations, private readonly ?Closure $connections = null) {}

    public function connect(MailAccount $account, Secret $secret): void
    {
        $settings = $account->incoming;
        $host = (string) $settings['host'];
        $port = (int) $settings['port'];
        $address = $this->destinations->approvedAddress($host, $port);
        $this->mailbox = new Mailbox([
            'host' => $host,
            'port' => $port,
            'username' => $settings['username'],
            'password' => $secret->password,
            'encryption' => $settings['security'] === 'tls' ? 'ssl' : 'starttls',
            'validate_cert' => true,
            'timeout' => (int) config('mailcenter.imap.timeout_seconds'),
            'debug' => false,
        ]);
        $connection = $this->connections ? ($this->connections)($address, $host)
            : new ImapConnection(new PinnedImapStream($address, $host));
        try {
            $this->mailbox->connect($connection);
        } catch (Throwable $e) {
            $this->mailbox = null;
            // Library messages/traces can echo command text; never carry them forward.
            throw new ImapFailure($this->connectCategory($e), ImapFailure::safeMessage($this->connectCategory($e)));
        }
    }

    private function connectCategory(Throwable $e): string
    {
        $text = strtolower($e->getMessage());
        if (str_contains($text, 'certificate') || str_contains($text, 'ssl') || str_contains($text, 'crypto') || str_contains($text, 'starttls')) {
            return ImapFailure::TLS;
        }

        // The only commands issued while connecting are STARTTLS and LOGIN.
        return $e instanceof ImapCommandException ? ImapFailure::AUTH : ImapFailure::TRANSIENT;
    }

    /** Runs one library call, converting every failure into a sanitized, classified ImapFailure. */
    private function guard(Closure $call, string $onCommandError = ImapFailure::TRANSIENT): mixed
    {
        try {
            return $call();
        } catch (ImapFailure $failure) {
            throw $failure;
        } catch (ImapCommandException) {
            throw new ImapFailure($onCommandError, ImapFailure::safeMessage($onCommandError));
        } catch (Throwable) {
            throw new ImapFailure(ImapFailure::TRANSIENT, ImapFailure::safeMessage(ImapFailure::TRANSIENT));
        }
    }

    private function foldersRaw(): array
    {
        $folders = [];
        foreach ($this->connection()->list() as $response) {
            $parts = $response->toArray();
            if (strtoupper((string) ($parts[1] ?? '')) !== 'LIST') {
                continue;
            }
            $attributes = is_array($parts[2] ?? null) ? $parts[2] : [];
            $raw = (string) ($parts[4] ?? '');
            if ($raw === '') {
                continue;
            }
            $role = $this->role($raw, $attributes);
            $folders[] = [
                'raw_name' => $raw, 'name' => $raw, 'delimiter' => $parts[3] ?? null,
                'attributes' => $attributes, 'role' => $role,
                'selectable' => ! in_array('\\Noselect', $attributes, true),
            ];
        }

        return $folders;
    }

    private function examineRaw(string $rawName): array
    {
        $result = ['uidvalidity' => 0, 'uidnext' => 0, 'exists' => 0];
        foreach ($this->connection()->examine($rawName) as $response) {
            $parts = $response->toArray();
            if (is_string($parts[2] ?? null) && strtoupper($parts[2]) === 'EXISTS') {
                $result['exists'] = (int) $parts[1];
            }
            foreach ($parts as $part) {
                if (is_array($part)) {
                    $label = is_string($part[0] ?? null) ? strtoupper($part[0]) : '';
                    if ($label === 'UIDVALIDITY' || $label === 'UIDNEXT') {
                        $result[strtolower($label)] = (int) ($part[1] ?? 0);
                    }
                }
            }
        }
        if ($result['uidvalidity'] < 1 || $result['uidnext'] < 1) {
            throw new RuntimeException('IMAP server did not provide mailbox identity.');
        }

        return $result;
    }

    private function searchRaw(int $low, int $high): array
    {
        if ($low < 1 || $high < $low) {
            return [];
        }
        $parts = $this->connection()->search(['UID', "$low:$high"])->toArray();
        $uids = array_values(array_filter(array_map('intval', array_slice($parts, 2)),
            fn (int $uid) => $uid >= $low && $uid <= $high));
        sort($uids, SORT_NUMERIC);

        return array_values(array_unique($uids));
    }

    private function fetchRaw(int $uid): ?array
    {
        $response = $this->connection()->fetch(
            ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[]'], $uid
        )->first();
        if ($response === null) {
            return null;
        }
        $values = $this->pairs($response->toArray()[3] ?? []);
        $raw = $values['BODY[]'] ?? null;
        if (! is_string($raw) || (int) ($values['UID'] ?? 0) !== $uid) {
            throw new RuntimeException('IMAP fetch returned incomplete data.');
        }

        return [
            'raw' => $raw,
            'flags' => (array) ($values['FLAGS'] ?? []),
            'internal_date' => isset($values['INTERNALDATE']) ? (string) $values['INTERNALDATE'] : null,
            'size' => (int) ($values['RFC822.SIZE'] ?? strlen($raw)),
        ];
    }

    private function metadataRaw(int $uid): ?array
    {
        $response = $this->connection()->fetch(['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE'], $uid)->first();
        if ($response === null) {
            return null;
        }
        $values = $this->pairs($response->toArray()[3] ?? []);
        if ((int) ($values['UID'] ?? 0) !== $uid) {
            return null;
        }

        return [
            'flags' => (array) ($values['FLAGS'] ?? []),
            'internal_date' => isset($values['INTERNALDATE']) ? (string) $values['INTERNALDATE'] : null,
            'size' => (int) ($values['RFC822.SIZE'] ?? 0),
        ];
    }

    private function flagsRaw(int $low, int $high): array
    {
        if ($low < 1 || $high < $low) {
            return [];
        }
        $flags = [];
        foreach ($this->connection()->fetch(['UID', 'FLAGS'], $low, $high) as $response) {
            $values = $this->pairs($response->toArray()[3] ?? []);
            $uid = (int) ($values['UID'] ?? 0);
            if ($uid >= $low && $uid <= $high) {
                $flags[$uid] = (array) ($values['FLAGS'] ?? []);
            }
        }

        return $flags;
    }

    public function folders(): array
    {
        return $this->guard(fn () => $this->foldersRaw(), ImapFailure::TRANSIENT);
    }

    public function examine(string $rawName): array
    {
        return $this->guard(fn () => $this->examineRaw($rawName), ImapFailure::FOLDER);
    }

    public function search(int $low, int $high): array
    {
        return $this->guard(fn () => $this->searchRaw($low, $high), ImapFailure::TRANSIENT);
    }

    public function fetch(int $uid): ?array
    {
        return $this->guard(fn () => $this->fetchRaw($uid), ImapFailure::MESSAGE);
    }

    public function metadata(int $uid): ?array
    {
        return $this->guard(fn () => $this->metadataRaw($uid), ImapFailure::MESSAGE);
    }

    public function flags(int $low, int $high): array
    {
        return $this->guard(fn () => $this->flagsRaw($low, $high), ImapFailure::TRANSIENT);
    }

    public function close(): void
    {
        $this->mailbox?->disconnect();
        $this->mailbox = null;
    }

    private function connection(): ConnectionInterface
    {
        if ($this->mailbox === null) {
            throw new RuntimeException('IMAP connection is not open.');
        }

        return $this->mailbox->connection();
    }

    /** Folds a FETCH data list into KEY => value; `BODY[]` arrives as three tokens: BODY, [], literal. */
    private function pairs(array $data): array
    {
        $pairs = [];
        $count = count($data);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $key = strtoupper((string) $data[$i]);
            if (in_array($key, ['BODY', 'BODY.PEEK'], true) && is_array($data[$i + 1])) {
                $pairs['BODY[]'] = $data[$i + 2] ?? null;
                $i++;

                continue;
            }
            $pairs[$key] = $data[$i + 1];
        }

        return $pairs;
    }

    private function role(string $name, array $attributes): string
    {
        $lower = strtolower($name);
        foreach (['\\Inbox' => 'inbox', '\\Sent' => 'sent', '\\All' => 'all', '\\Archive' => 'archive', '\\Trash' => 'trash', '\\Junk' => 'junk', '\\Drafts' => 'drafts', '\\Flagged' => 'flagged', '\\Important' => 'important'] as $attribute => $role) {
            if (in_array($attribute, $attributes, true)) {
                return $role;
            }
        }

        return match (true) {
            $lower === 'inbox' => 'inbox',
            str_contains($lower, 'sent') => 'sent',
            str_contains($lower, 'trash') => 'trash',
            str_contains($lower, 'junk') || str_contains($lower, 'spam') => 'junk',
            str_contains($lower, 'draft') => 'drafts',
            default => 'other',
        };
    }
}
