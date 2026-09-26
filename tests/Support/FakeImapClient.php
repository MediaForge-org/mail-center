<?php

namespace Tests\Support;

use App\Accounts\Credentials\Secret;
use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Models\MailAccount;
use Closure;
use Throwable;

/** Deterministic in-memory IMAP mailbox. Records every command so tests can assert read-only use. */
class FakeImapClient implements ImapClient
{
    /** @var array<string, array{uidvalidity:int, uidnext:int, role:string, messages:array<int, array{raw:string, flags:array, date:string}>}> */
    public array $mailboxes = [];

    public ?ImapFailure $connectFailure = null;

    /** @var array<int, Throwable|ImapFailure> UID => failure raised by fetch() */
    public array $fetchFailures = [];

    public ?Closure $onFetch = null;

    public ?Closure $onStore = null;

    public bool $ignoreStore = false;

    public ?ImapFailure $storeFailure = null;

    /** @var string[] */
    public array $calls = [];

    public int $connects = 0;

    private ?string $selected = null;

    public function __construct()
    {
        $this->mailbox('INBOX', 'inbox');
    }

    public function mailbox(string $name, string $role = 'other', int $uidvalidity = 1000): static
    {
        $this->mailboxes[$name] ??= ['uidvalidity' => $uidvalidity, 'uidnext' => 1, 'role' => $role, 'messages' => []];

        return $this;
    }

    public function add(string $raw, array $flags = [], string $folder = 'INBOX'): int
    {
        $uid = $this->mailboxes[$folder]['uidnext']++;
        $this->mailboxes[$folder]['messages'][$uid] = ['raw' => $raw, 'flags' => $flags, 'date' => '01-Jan-2026 10:00:00 +0000'];

        return $uid;
    }

    public function expunge(int $uid, string $folder = 'INBOX'): void
    {
        unset($this->mailboxes[$folder]['messages'][$uid]);
    }

    public function resetUidValidity(string $folder, int $uidvalidity): void
    {
        $this->mailboxes[$folder]['uidvalidity'] = $uidvalidity;
        $this->mailboxes[$folder]['messages'] = [];
        $this->mailboxes[$folder]['uidnext'] = 1;
    }

    public static function raw(string $subject, string $body = 'Hello', ?string $messageId = null): string
    {
        return "From: Sender <sender@example.test>\r\nTo: me@example.test\r\nSubject: {$subject}\r\n"
            .'Message-ID: <'.($messageId ?? md5($subject.$body))."@example.test>\r\nDate: Thu, 01 Jan 2026 10:00:00 +0000\r\n"
            ."Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";
    }

    public function connect(MailAccount $account, Secret $secret): void
    {
        $this->connects++;
        $this->calls[] = 'CONNECT';
        if ($this->connectFailure) {
            throw $this->connectFailure;
        }
    }

    public function folders(): array
    {
        $this->calls[] = 'LIST';

        return collect($this->mailboxes)->map(fn ($box, $name) => [
            'raw_name' => $name, 'name' => $name, 'delimiter' => '/', 'attributes' => [],
            'role' => $box['role'], 'selectable' => true,
        ])->values()->all();
    }

    public function examine(string $rawName): array
    {
        $this->calls[] = "EXAMINE {$rawName}";
        if (! isset($this->mailboxes[$rawName])) {
            throw new ImapFailure(ImapFailure::FOLDER, 'missing');
        }
        $this->selected = $rawName;
        $box = $this->mailboxes[$rawName];

        return ['uidvalidity' => $box['uidvalidity'], 'uidnext' => $box['uidnext'], 'exists' => count($box['messages'])];
    }

    public function search(int $low, int $high): array
    {
        $this->calls[] = "UID SEARCH {$low}:{$high}";

        return array_values(array_filter(array_keys($this->box()['messages']), fn ($uid) => $uid >= $low && $uid <= $high));
    }

    public function metadata(int $uid): ?array
    {
        $this->calls[] = "UID FETCH {$uid} (META)";
        $message = $this->box()['messages'][$uid] ?? null;

        return $message ? ['flags' => $message['flags'], 'internal_date' => $message['date'], 'size' => strlen($message['raw'])] : null;
    }

    public function fetch(int $uid): ?array
    {
        $this->calls[] = "UID FETCH {$uid} (BODY.PEEK[])";
        if ($this->onFetch) {
            ($this->onFetch)($uid);
        }
        if (isset($this->fetchFailures[$uid])) {
            throw $this->fetchFailures[$uid];
        }
        $message = $this->box()['messages'][$uid] ?? null;

        return $message ? ['raw' => $message['raw'], 'flags' => $message['flags'], 'internal_date' => $message['date'], 'size' => strlen($message['raw'])] : null;
    }

    public function flags(int $low, int $high): array
    {
        $this->calls[] = "UID FETCH {$low}:{$high} (FLAGS)";

        return collect($this->box()['messages'])->filter(fn ($m, $uid) => $uid >= $low && $uid <= $high)->map(fn ($m) => $m['flags'])->all();
    }

    public function selectForSeen(string $rawName): array
    {
        $box = $this->examine($rawName);
        $this->calls[] = "SELECT {$rawName}";

        return ['uidvalidity' => $box['uidvalidity'], 'writable_seen' => true];
    }

    public function storeSeen(array $uids, bool $seen): void
    {
        $this->calls[] = 'UID STORE '.implode(',', $uids).' '.($seen ? '+' : '-').'FLAGS.SILENT (\\Seen)';
        if ($this->storeFailure) {
            throw $this->storeFailure;
        }
        if ($this->onStore) {
            ($this->onStore)($uids, $seen);
        }
        if ($this->ignoreStore) {
            return;
        }
        foreach ($uids as $uid) {
            if (! isset($this->mailboxes[$this->selected]['messages'][$uid])) {
                continue;
            }
            $flags = array_values(array_diff($this->mailboxes[$this->selected]['messages'][$uid]['flags'], ['\\Seen']));
            if ($seen) {
                $flags[] = '\\Seen';
            }
            $this->mailboxes[$this->selected]['messages'][$uid]['flags'] = $flags;
        }
    }

    public function flagsForUids(array $uids): array
    {
        $this->calls[] = 'UID FETCH '.implode(',', $uids).' (FLAGS)';

        return array_intersect_key(array_map(fn ($m) => $m['flags'], $this->box()['messages']), array_flip($uids));
    }

    public function close(): void
    {
        $this->calls[] = 'DISCONNECT';
        $this->selected = null;
    }

    private function box(): array
    {
        return $this->mailboxes[$this->selected];
    }
}
