<?php

use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Ingestion\MessageIngestor;
use App\Models\MailAccount;
use App\Models\User;
use App\Organization\SystemFolders;
use App\Sync\AccountSyncLock;
use App\Sync\SyncAborted;
use App\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeImapClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->blobRoot = sys_get_temp_dir().'/mailcenter-test-'.bin2hex(random_bytes(4));
    config(['mailcenter.blobs.root' => $this->blobRoot]);
    $this->imap = new FakeImapClient;
    $this->app->instance(ImapClient::class, $this->imap);
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
    $this->runner = fn () => app(SyncRunner::class)->run($this->account->id, 'test');
});

afterEach(function () {
    if (is_dir($this->blobRoot)) {
        exec('rm -rf '.escapeshellarg($this->blobRoot));
    }
});

it('synchronizes INBOX initially, newest first, and stays idempotent', function () {
    foreach (range(1, 5) as $i) {
        $this->imap->add(FakeImapClient::raw("Mail {$i}", "Body {$i}"), $i === 1 ? ['\\Seen'] : []);
    }

    expect(($this->runner)())->toBe('success');
    expect(DB::table('messages')->count())->toBe(5);
    expect(DB::table('message_locations')->count())->toBe(5);
    expect(DB::table('blobs')->count())->toBe(5);
    expect(DB::table('messages')->where('subject', 'Mail 1')->value('remote_seen'))->toBeTrue();

    $account = $this->account->refresh();
    expect($account->sync_status)->toBe('idle')
        ->and($account->last_successful_sync_at)->not->toBeNull()
        ->and($account->next_sync_at->isFuture())->toBeTrue();
    $folder = $account->remoteFolders()->where('raw_name', 'INBOX')->first();
    expect($folder->sync_high_uid)->toBe(5)->and($folder->backfill_completed_at)->not->toBeNull();

    ($this->runner)();
    expect(DB::table('messages')->count())->toBe(5)
        ->and(DB::table('message_locations')->count())->toBe(5)
        ->and(DB::table('blobs')->count())->toBe(5);
    expect(DB::table('sync_runs')->where('mail_account_id', $account->id)->pluck('status')->all())->toBe(['success', 'success']);
});

it('only synchronizes INBOX by default and never issues a remote write', function () {
    $this->imap->mailbox('Sent', 'sent')->mailbox('Archive');
    $this->imap->add(FakeImapClient::raw('Inbox'));
    $this->imap->add(FakeImapClient::raw('Sent one'), [], 'Sent');

    ($this->runner)();

    expect(DB::table('messages')->count())->toBe(1);
    expect($this->account->remoteFolders()->where('sync_enabled', true)->pluck('raw_name')->all())->toBe(['INBOX']);
    expect($this->account->remoteFolders()->count())->toBe(3);
    foreach ($this->imap->calls as $call) {
        expect($call)->toMatch('/^(CONNECT|LIST|CLOSE|EXAMINE |UID SEARCH |UID FETCH )/');
        expect($call)->not->toMatch('/STORE|EXPUNGE|COPY|MOVE|DELETE|APPEND|SELECT /');
    }
});

it('fetches only new UIDs incrementally using the persisted cursor', function () {
    $this->imap->add(FakeImapClient::raw('First'));
    ($this->runner)();
    $this->imap->calls = [];
    $this->imap->add(FakeImapClient::raw('Second'));

    ($this->runner)();

    expect(DB::table('messages')->count())->toBe(2);
    $fetches = collect($this->imap->calls)->filter(fn ($c) => str_contains($c, 'BODY.PEEK'))->values()->all();
    expect($fetches)->toBe(['UID FETCH 2 (BODY.PEEK[])']);
});

it('links identical content in two locations to one message without merging distinct messages', function () {
    $same = FakeImapClient::raw('Same', 'identical bytes', 'shared-id');
    $this->imap->add($same);
    $this->imap->add($same); // a second remote copy in the same folder
    $this->imap->add(FakeImapClient::raw('Other', 'different body', 'shared-id')); // same Message-ID, different content

    ($this->runner)();

    expect(DB::table('messages')->count())->toBe(2);
    expect(DB::table('message_locations')->count())->toBe(3);
    expect(DB::table('message_locations')->distinct('message_id')->count('message_id'))->toBe(2);
    expect(DB::table('messages')->where('message_id_header', 'shared-id@example.test')->count())->toBe(2);
});

it('sets the local folder from the first ingested remote role and preserves it for later copies', function () {
    $sent = $this->account->remoteFolders()->create(['raw_name' => 'Sent', 'name' => 'Sent', 'role' => 'sent']);
    $inbox = $this->account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $other = $this->account->remoteFolders()->create(['raw_name' => 'Other', 'name' => 'Other', 'role' => 'other']);
    $ingestor = app(MessageIngestor::class);

    $sentId = $ingestor->ingest($this->account, $sent, 1, 1000, FakeImapClient::raw('Sent placement'), [], null);
    $inboxId = $ingestor->ingest($this->account, $inbox, 1, 1000, FakeImapClient::raw('Inbox placement'), [], null);
    $archiveId = $ingestor->ingest($this->account, $other, 1, 1000, FakeImapClient::raw('Archive placement'), [], null);
    expect((int) DB::table('messages')->where('id', $sentId)->value('folder_id'))->toBe(SystemFolders::idFor($this->user->id, 'sent'));
    expect((int) DB::table('messages')->where('id', $inboxId)->value('folder_id'))->toBe(SystemFolders::idFor($this->user->id, 'inbox'));
    expect((int) DB::table('messages')->where('id', $archiveId)->value('folder_id'))->toBe(SystemFolders::idFor($this->user->id, 'archive'));

    $ingestor->ingest($this->account, $inbox, 2, 1000, FakeImapClient::raw('Sent placement'), [], null);
    expect((int) DB::table('messages')->where('id', $sentId)->value('folder_id'))->toBe(SystemFolders::idFor($this->user->id, 'sent'));
});

it('does not duplicate messages when ingestion is retried after a crash', function () {
    $this->imap->add(FakeImapClient::raw('Crash'));
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $ingestor = app(MessageIngestor::class);
    $raw = FakeImapClient::raw('Crash');

    $ingestor->ingest($this->account, $folder, 1, 1000, $raw, [], null);
    $ingestor->ingest($this->account, $folder, 1, 1000, $raw, ['\\Seen'], null);

    expect(DB::table('messages')->count())->toBe(1)->and(DB::table('message_locations')->count())->toBe(1);
    expect(DB::table('messages')->value('remote_seen'))->toBeTrue();
});

it('marks expunged messages removed only after grace and never deletes them', function () {
    $uid = $this->imap->add(FakeImapClient::raw('Going away'));
    $this->imap->add(FakeImapClient::raw('Staying'));
    ($this->runner)();
    $this->imap->expunge($uid);

    ($this->runner)();
    expect(DB::table('messages')->count())->toBe(2);
    expect(DB::table('message_locations')->whereNotNull('removed_at')->value('removed_reason'))->toBe('expunged');
    expect(DB::table('messages')->where('subject', 'Going away')->value('remote_status'))->toBe('missing');

    DB::table('messages')->update(['remote_missing_since' => now()->subDays(2)]);
    ($this->runner)();
    expect(DB::table('messages')->where('subject', 'Going away')->value('remote_status'))->toBe('removed');
    expect(DB::table('messages')->where('subject', 'Staying')->value('remote_status'))->toBe('present');
    expect(DB::table('messages')->count())->toBe(2);
});

it('handles a UIDVALIDITY change by retiring old locations and re-linking identical content', function () {
    $this->imap->add(FakeImapClient::raw('Keep me', 'stable content'));
    ($this->runner)();
    $messageId = DB::table('messages')->value('id');
    DB::table('messages')->update(['is_starred' => true, 'is_done' => true]); // local organization

    $this->imap->resetUidValidity('INBOX', 2000);
    $this->imap->add(FakeImapClient::raw('Keep me', 'stable content')); // now UID 1 in the new generation
    $this->imap->add(FakeImapClient::raw('Brand new'));

    ($this->runner)();

    $folder = $this->account->remoteFolders()->first();
    expect($folder->uidvalidity)->toBe(2000);
    $old = DB::table('message_locations')->where('uidvalidity', 1000)->first();
    expect($old->removed_at)->not->toBeNull()->and($old->removed_reason)->toBe('uidvalidity_reset');
    // Same bytes re-link to the existing message; local organization is untouched.
    expect(DB::table('message_locations')->where('uidvalidity', 2000)->whereNull('removed_at')->where('message_id', $messageId)->count())->toBe(1);
    expect(DB::table('messages')->count())->toBe(2);
    $message = DB::table('messages')->find($messageId);
    expect($message->is_starred)->toBeTrue()->and($message->is_done)->toBeTrue()->and($message->remote_status)->toBe('present');
    expect(DB::table('audit_events')->where('action', 'sync.uidvalidity_reset')->count())->toBe(1);
});

it('processes many windows in one run and does nothing when the budget is already spent', function () {
    config(['mailcenter.imap.uids_per_batch' => 2, 'mailcenter.imap.uid_window' => 3]);
    foreach (range(1, 7) as $i) {
        $this->imap->add(FakeImapClient::raw("Bulk {$i}"));
    }
    config(['mailcenter.imap.work_seconds' => 0]);

    expect(($this->runner)())->toBe('success');
    expect(DB::table('messages')->count())->toBe(0);
    expect($this->account->refresh()->sync_status)->toBe('syncing')
        ->and($this->account->next_sync_at->lte(now()))->toBeTrue();

    config(['mailcenter.imap.work_seconds' => 240]);
    expect(($this->runner)())->toBe('success');
    expect(DB::table('messages')->count())->toBe(7)->and(DB::table('message_locations')->count())->toBe(7);
    expect($this->account->refresh()->sync_status)->toBe('idle');
});

it('resumes from its checkpoint after a mid-run crash without duplicating anything', function () {
    config(['mailcenter.imap.uids_per_batch' => 1, 'mailcenter.imap.uid_window' => 3]);
    foreach (range(1, 7) as $i) {
        $this->imap->add(FakeImapClient::raw("Bulk {$i}"));
    }
    $this->imap->fetchFailures[5] = new ImapFailure(ImapFailure::TRANSIENT, 'connection dropped');

    expect(($this->runner)())->toBe('failed');
    $folder = $this->account->remoteFolders()->first();
    expect(DB::table('messages')->count())->toBe(2); // UIDs 7 and 6, newest first
    expect($folder->backfill_low_uid)->toBe(8); // the unfinished window is not checkpointed

    unset($this->imap->fetchFailures[5]);
    DB::table('mail_accounts')->update(['next_sync_at' => now()->subMinute()]);
    expect(($this->runner)())->toBe('success');
    expect(DB::table('messages')->count())->toBe(7)->and(DB::table('message_locations')->count())->toBe(7);
    expect($this->account->remoteFolders()->first()->backfill_completed_at)->not->toBeNull();
});

it('quarantines a failing message, keeps syncing others, and retries it with backoff', function () {
    $this->imap->add(FakeImapClient::raw('Good'));
    $bad = $this->imap->add(FakeImapClient::raw('Flaky'));
    $this->imap->add(FakeImapClient::raw('Also good'));
    $this->imap->fetchFailures[$bad] = new ImapFailure(ImapFailure::MESSAGE, 'x');

    expect(($this->runner)())->toBe('success');
    expect(DB::table('messages')->count())->toBe(2);
    $failure = DB::table('sync_failures')->first();
    expect($failure->uid)->toBe($bad)->and($failure->status)->toBe('retryable')->and($failure->attempts)->toBe(1);
    expect($this->account->refresh()->sync_status)->toBe('idle');

    // Not due yet: it is not retried.
    ($this->runner)();
    expect(DB::table('sync_failures')->value('attempts'))->toBe(1);

    DB::table('sync_failures')->update(['next_attempt_at' => now()->subMinute()]);
    ($this->runner)();
    expect(DB::table('sync_failures')->value('attempts'))->toBe(2);

    unset($this->imap->fetchFailures[$bad]);
    DB::table('sync_failures')->update(['next_attempt_at' => now()->subMinute()]);
    ($this->runner)();
    expect(DB::table('messages')->count())->toBe(3);
    expect(DB::table('sync_failures')->value('status'))->toBe('resolved');
});

it('stops retrying a message after five attempts', function () {
    $bad = $this->imap->add(FakeImapClient::raw('Broken'));
    $this->imap->fetchFailures[$bad] = new RuntimeException('parse crash');
    foreach (range(1, 5) as $attempt) {
        DB::table('sync_failures')->update(['next_attempt_at' => now()->subMinute()]);
        ($this->runner)();
    }
    expect(DB::table('sync_failures')->value('status'))->toBe('manual')
        ->and(DB::table('sync_failures')->value('attempts'))->toBe(5);
});

it('quarantines oversized messages without downloading them', function () {
    config(['mailcenter.imap.max_message_bytes' => 100]);
    $uid = $this->imap->add(FakeImapClient::raw('Huge', str_repeat('x', 500)));

    ($this->runner)();

    expect(DB::table('messages')->count())->toBe(0);
    expect(DB::table('sync_failures')->where('uid', $uid)->value('error_code'))->toBe('too_large');
    expect(collect($this->imap->calls)->contains("UID FETCH {$uid} (BODY.PEEK[])"))->toBeFalse();
});

it('does not charge connection loss to individual messages', function () {
    $uid = $this->imap->add(FakeImapClient::raw('Victim'));
    $this->imap->fetchFailures[$uid] = new ImapFailure(ImapFailure::TRANSIENT, 'dropped');

    expect(($this->runner)())->toBe('failed');

    expect(DB::table('sync_failures')->count())->toBe(0);
    expect($this->account->refresh()->sync_status)->toBe('backing_off');
});

it('classifies authentication failure as terminal and never persists secrets', function () {
    $this->imap->connectFailure = new ImapFailure(ImapFailure::AUTH, 'no: imap-secret-pw');

    expect(($this->runner)())->toBe('failed');

    $account = $this->account->refresh();
    expect($account->sync_status)->toBe('auth_failed')
        ->and($account->next_sync_at)->toBeNull()
        ->and($account->last_error_code)->toBe('auth_failed')
        ->and($account->consecutive_failures)->toBe(1);
    expect(json_encode([$account->toArray(), DB::table('sync_runs')->get()]))->not->toContain('imap-secret-pw');
});

it('applies increasing backoff for transient failures and a fixed hour for certificate errors', function () {
    $this->imap->connectFailure = new ImapFailure(ImapFailure::TRANSIENT, 'down');
    $delays = [];
    foreach (range(1, 3) as $i) {
        ($this->runner)();
        $delays[] = $this->account->refresh()->next_sync_at->diffInSeconds(now(), true);
    }
    expect($this->account->sync_status)->toBe('backing_off');
    expect($delays[0])->toBeBetween(53, 67)->and($delays[1])->toBeBetween(107, 133)->and($delays[2])->toBeBetween(268, 332);

    $this->imap->connectFailure = new ImapFailure(ImapFailure::TLS, 'cert');
    ($this->runner)();
    expect($this->account->refresh()->sync_status)->toBe('error')
        ->and($this->account->next_sync_at->diffInMinutes(now(), true))->toBeBetween(58, 61);

    $this->imap->connectFailure = null;
    ($this->runner)();
    expect($this->account->refresh())->sync_status->toBe('idle')->consecutive_failures->toBe(0)->last_error_code->toBeNull();
});

it('reports unexpected errors without leaking exception text', function () {
    $broken = new class extends FakeImapClient
    {
        public function folders(): array
        {
            throw new LogicException('boom imap-secret-pw');
        }
    };
    $this->app->instance(ImapClient::class, $broken);

    expect(($this->runner)())->toBe('failed');
    $account = $this->account->refresh();
    expect($account->last_error_code)->toBe('unexpected_error')->and($account->sync_status)->toBe('backing_off');
    expect(json_encode([$account->toArray(), DB::table('sync_runs')->get()]))->not->toContain('imap-secret-pw');
});

it('skips accounts that are disabled or removed', function () {
    $this->account->update(['sync_enabled' => false]);
    expect(($this->runner)())->toBe('skipped');
    $this->account->update(['sync_enabled' => true]);
    $this->account->delete();
    expect(($this->runner)())->toBe('skipped');
    expect($this->imap->connects)->toBe(0);
});

it('aborts promptly when the account is disabled during a run and keeps data intact', function () {
    foreach (range(1, 3) as $i) {
        $this->imap->add(FakeImapClient::raw("Msg {$i}"));
    }
    $this->imap->onFetch = function (int $uid) {
        if ($uid === 3) { // backfill runs newest first
            MailAccount::query()->whereKey($this->account->id)->update(['sync_enabled' => false]);
        }
    };
    config(['mailcenter.imap.uids_per_batch' => 1]);

    expect(($this->runner)())->toBe('aborted');

    expect(DB::table('messages')->count())->toBe(1);
    expect(DB::table('sync_runs')->value('status'))->toBe('aborted');
    expect($this->account->refresh()->next_sync_at)->toBeNull();
});

it('refuses a concurrent sync of the same mailbox and releases the lock afterwards', function () {
    $config = config('database.connections.sync_lock');
    $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    $other->query('SELECT pg_advisory_lock('.(0x4D43).', '.$this->account->id.')');
    $this->imap->add(FakeImapClient::raw('Contended'));

    expect(($this->runner)())->toBe('locked');
    expect($this->imap->connects)->toBe(0)->and(DB::table('messages')->count())->toBe(0);
    expect($this->account->refresh()->sync_status)->toBe('never_synced');

    $other->query('SELECT pg_advisory_unlock('.(0x4D43).', '.$this->account->id.')');
    expect(($this->runner)())->toBe('success');
    expect(DB::table('messages')->count())->toBe(1);
    // Released by the runner: a different session can now take it.
    $free = $other->query('SELECT pg_try_advisory_lock('.(0x4D43).', '.$this->account->id.')')->fetchColumn();
    expect($free)->toBeTrue();
});

it('detects a lost advisory lock instead of continuing to write', function () {
    $lock = app(AccountSyncLock::class);
    expect($lock->acquire($this->account))->toBeTrue();
    expect($lock->acquire($this->account))->toBeFalse(); // not re-entrant within the process
    $lock->assertHeld($this->account);

    DB::connection('sync_lock')->select('SELECT pg_advisory_unlock_all()'); // simulates a lost session
    expect(fn () => $lock->assertHeld($this->account))->toThrow(SyncAborted::class);
    $lock->release($this->account);
});

it('normalizes Date and INTERNALDATE offsets to UTC so sort_date is correct', function () {
    $raw = str_replace('Date: Thu, 01 Jan 2026 10:00:00 +0000', 'Date: Sat, 26 Sep 2026 00:58:18 +0200', FakeImapClient::raw('Vienna'));
    $uid = $this->imap->add($raw);
    $this->imap->mailboxes['INBOX']['messages'][$uid]['date'] = '26-Sep-2026 00:58:31 +0200';

    ($this->runner)();

    $message = DB::table('messages')->first();
    expect(Carbon\Carbon::parse($message->date_header)->utc()->toIso8601String())->toBe('2026-09-25T22:58:18+00:00');
    expect(Carbon\Carbon::parse($message->received_at)->utc()->toIso8601String())->toBe('2026-09-25T22:58:31+00:00');
    expect(Carbon\Carbon::parse($message->sort_date)->utc()->toIso8601String())->toBe('2026-09-25T22:58:31+00:00');
});

it('sorts by received time and falls back to the Date header when INTERNALDATE is missing', function () {
    $raw = str_replace('Date: Thu, 01 Jan 2026 10:00:00 +0000', 'Date: Sat, 26 Sep 2026 00:58:18 +0200', FakeImapClient::raw('No internal date'));
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);

    app(MessageIngestor::class)->ingest($this->account, $folder, 1, 1000, $raw, [], null);

    expect(Carbon\Carbon::parse(DB::table('messages')->value('sort_date'))->utc()->toIso8601String())->toBe('2026-09-25T22:58:18+00:00');
});
