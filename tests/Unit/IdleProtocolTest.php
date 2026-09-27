<?php

use App\Connectors\Imap\IdleImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;

it('uses parsed IDLE notifications and DONE without CLOSE or remote writes', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK ready', '+ idling', '* 4 EXISTS', '* 2 EXPUNGE', 'TAG1 OK IDLE completed', '+ idling', '* 3 FETCH (FLAGS (\\Seen))', 'TAG2 OK IDLE completed']);
    $connection = new IdleImapConnection($stream);
    $connection->connect('synthetic.test');
    $connection->beginIdle();
    expect($connection->notification())->toBeTrue();
    $connection->endIdle();
    $connection->beginIdle();
    expect($connection->notification())->toBeTrue();
    $connection->endIdle();
    $connection->disconnect();
    $sent = implode('', (new ReflectionProperty($stream, 'written'))->getValue($stream));
    expect($sent)->toContain('IDLE')->toContain('DONE')->not->toContain('CLOSE')->not->toContain('STORE');
});

it('reconnects instead of silently waiting after the server ends IDLE', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK ready', '+ idling', 'TAG1 OK IDLE completed']);
    $connection = new IdleImapConnection($stream);
    $connection->connect('synthetic.test');
    $connection->beginIdle();
    expect(fn () => $connection->notification())->toThrow(RuntimeException::class);
    $connection->disconnect();
});
