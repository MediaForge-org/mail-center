<?php

use App\Accounts\Credentials\Secret;
use App\Connectors\Imap\DestinationPolicy;
use App\Connectors\Imap\ImapFailure;
use App\Connectors\Imap\LibraryImapClient;
use App\Models\MailAccount;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;

function imapAccount(): MailAccount
{
    return new MailAccount(['incoming' => ['host' => '93.184.216.34', 'port' => 993, 'security' => 'tls', 'username' => 'me']]);
}

function clientWith(FakeStream $stream): LibraryImapClient
{
    return new LibraryImapClient(new DestinationPolicy, fn () => new ImapConnection($stream));
}

it('parses LIST, EXAMINE, SEARCH, FETCH and FLAGS responses from a standards-compliant server', function () {
    $body = "Subject: Hi\r\n\r\nHello there\r\n";
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK IMAP4rev1 ready',
        'TAG1 OK LOGIN completed',
        '* LIST (\HasNoChildren) "/" "INBOX"',
        '* LIST (\HasNoChildren \Sent) "/" "Sent Items"',
        '* LIST (\Noselect \HasChildren) "/" "[Gmail]"',
        'TAG2 OK LIST completed',
        '* 3 EXISTS',
        '* OK [UIDVALIDITY 3857529045] UIDs valid',
        '* OK [UIDNEXT 4392] Predicted next UID',
        'TAG3 OK [READ-ONLY] EXAMINE completed',
        '* SEARCH 4390 4391 12 99999',
        'TAG4 OK SEARCH completed',
        '* 2 FETCH (UID 4391 FLAGS (\Seen \Answered) INTERNALDATE "17-Jul-2026 02:44:25 -0700" RFC822.SIZE 123)',
        'TAG5 OK FETCH completed',
    ]);
    $stream->feedRaw(['* 2 FETCH (UID 4391 FLAGS (\Seen) INTERNALDATE "17-Jul-2026 02:44:25 -0700" RFC822.SIZE '.strlen($body).' BODY[] {'.strlen($body)."}\r\n".$body.")\r\n"]);
    $stream->feed([
        'TAG6 OK FETCH completed',
        '* 1 FETCH (UID 4390 FLAGS ())',
        '* 2 FETCH (UID 4391 FLAGS (\Seen \Flagged))',
        'TAG7 OK FETCH completed',
    ]);

    $client = clientWith($stream);
    $client->connect(imapAccount(), new Secret('pw'));

    $folders = $client->folders();
    expect(array_column($folders, 'raw_name'))->toBe(['INBOX', 'Sent Items', '[Gmail]']);
    expect(array_column($folders, 'role'))->toBe(['inbox', 'sent', 'other']);
    expect(array_column($folders, 'selectable'))->toBe([true, true, false]);

    expect($client->examine('INBOX'))->toBe(['uidvalidity' => 3857529045, 'uidnext' => 4392, 'exists' => 3]);
    expect($client->search(4000, 5000))->toBe([4390, 4391]); // out-of-window UIDs are discarded

    $meta = $client->metadata(4391);
    expect($meta['flags'])->toBe(['\\Seen', '\\Answered'])->and($meta['size'])->toBe(123);

    $fetched = $client->fetch(4391);
    expect($fetched['raw'])->toBe($body)->and($fetched['flags'])->toBe(['\\Seen']);

    expect($client->flags(4390, 4391))->toBe([4390 => [], 4391 => ['\\Seen', '\\Flagged']]);
    $client->close();
});

it('only ever sends read-oriented commands and uses BODY.PEEK', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK ready', 'TAG1 OK done', '* 0 EXISTS', '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 1]', 'TAG2 OK ok', '* 1 FETCH (UID 1 FLAGS ())', 'TAG3 OK ok', '* 1 FETCH (UID 1 FLAGS () BODY[] {2}', 'x)', 'TAG4 OK ok']);
    $client = clientWith($stream);
    $client->connect(imapAccount(), new Secret('pw'));
    $client->examine('INBOX');
    $client->metadata(1);
    try {
        $client->fetch(1);
    } catch (ImapFailure) {
        // Response shape is irrelevant here; only the command text matters.
    }

    $sent = strtoupper(implode('', (new ReflectionProperty($stream, 'written'))->getValue($stream)));
    expect($sent)->toContain('EXAMINE')->toContain('BODY.PEEK[]')->not->toContain('SELECT')->not->toContain('STORE')->not->toContain('EXPUNGE')
        ->not->toContain('DELETE')->not->toContain('COPY')->not->toContain('APPEND')->not->toContain('MOVE');
});

it('classifies a rejected login as an authentication failure without leaking the password', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK ready', 'TAG1 NO [AUTHENTICATIONFAILED] Invalid credentials for hunter2-secret']);

    try {
        clientWith($stream)->connect(imapAccount(), new Secret('hunter2-secret'));
        $this->fail('Expected an ImapFailure');
    } catch (ImapFailure $failure) {
        expect($failure->category)->toBe(ImapFailure::AUTH)->and($failure->getMessage())->not->toContain('hunter2')
            ->and($failure->getPrevious())->toBeNull();
    }
});

it('classifies EXAMINE refusal as a folder problem and dropped connections as transient', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK ready', 'TAG1 OK done', 'TAG2 NO [NONEXISTENT] Unknown Mailbox']);
    $client = clientWith($stream);
    $client->connect(imapAccount(), new Secret('pw'));
    expect(fn () => $client->examine('Nope'))->toThrow(fn (ImapFailure $e) => $e->category === ImapFailure::FOLDER);

    $dropped = new FakeStream;
    $dropped->open();
    $dropped->feed(['* OK ready', 'TAG1 OK done']);
    $client = clientWith($dropped);
    $client->connect(imapAccount(), new Secret('pw'));
    $dropped->setMeta('eof', true);
    try {
        $client->search(1, 10);
        $this->fail('Expected an ImapFailure');
    } catch (ImapFailure $failure) {
        expect($failure->category)->toBe(ImapFailure::TRANSIENT);
    }
});

it('refuses to connect to disallowed destinations before opening any socket', function () {
    $opened = false;
    $client = new LibraryImapClient(new DestinationPolicy, function () use (&$opened) {
        $opened = true;
    });
    $account = new MailAccount(['incoming' => ['host' => '127.0.0.1', 'port' => 993, 'security' => 'tls', 'username' => 'x']]);

    expect(fn () => $client->connect($account, new Secret('pw')))->toThrow(fn (ImapFailure $e) => $e->category === ImapFailure::DESTINATION);
    expect($opened)->toBeFalse();
});

it('uses UID STORE Seen only and verifies via UID FETCH without CLOSE or expunge', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK ready', 'TAG1 OK login',
        '* OK [UIDVALIDITY 1000]', '* OK [PERMANENTFLAGS (\\Seen \\*)]', 'TAG2 OK [READ-WRITE] selected',
        'TAG3 OK store', '* 1 FETCH (UID 4 FLAGS (\\Seen))', '* 2 FETCH (UID 7 FLAGS (\\Seen))', 'TAG4 OK fetched',
        'TAG5 OK store', '* BYE bye', 'TAG6 OK logout',
    ]);
    $client = clientWith($stream);
    $client->connect(imapAccount(), new Secret('pw'));
    expect($client->selectForSeen('INBOX'))->toBe(['uidvalidity' => 1000, 'writable_seen' => true]);
    $client->storeSeen([4, 7], true);
    expect($client->flagsForUids([4, 7]))->toBe([4 => ['\\Seen'], 7 => ['\\Seen']]);
    $client->storeSeen([4, 7], false);
    $client->close();
    $sent = implode('', (new ReflectionProperty($stream, 'written'))->getValue($stream));
    expect($sent)->toContain('UID STORE 4,7 +FLAGS.SILENT (\\Seen)', 'UID STORE 4,7 -FLAGS.SILENT (\\Seen)')
        ->not->toContain('CLOSE', 'EXPUNGE', '\\Deleted', '\\Flagged');
});
