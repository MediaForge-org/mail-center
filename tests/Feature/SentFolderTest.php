<?php

use App\Connectors\Imap\ImapClient;
use App\Models\User;
use App\Sync\Idle\IdleClient;
use App\Sync\Idle\IdleWatcher;
use App\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdleClient;
use Tests\Support\FakeImapClient;

uses(RefreshDatabase::class);

function mime(string $subject, string $from, string $to, string $id, ?string $inReplyTo = null): string
{
    return "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <{$id}@example.test>\r\n"
        .($inReplyTo ? "In-Reply-To: <{$inReplyTo}@example.test>\r\nReferences: <{$inReplyTo}@example.test>\r\n" : '')
        ."Date: Thu, 01 Jan 2026 10:00:00 +0000\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody {$id}\r\n";
}

function sentIds($test, int $userId, string $query = ''): array
{
    return collect($test->actingAs(User::find($userId))->getJson('/api/messages?view=sent'.$query)->assertOk()->json('data'))
        ->pluck('subject')->sort()->values()->all();
}

beforeEach(function () {
    $this->blobRoot = sys_get_temp_dir().'/mailcenter-test-'.bin2hex(random_bytes(4));
    config(['mailcenter.blobs.root' => $this->blobRoot]);
    $this->imap = new FakeImapClient;
    $this->imap->mailbox('[Gmail]/Gesendet', 'sent')->mailbox('[Gmail]/Spam', 'junk')->mailbox('Drafts', 'drafts')->mailbox('Custom');
    $this->app->instance(ImapClient::class, $this->imap);
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
    $this->run = fn ($account = null) => app(SyncRunner::class)->run(($account ?? $this->account)->id, 'test');
});

afterEach(function () {
    if (is_dir($this->blobRoot)) {
        exec('rm -rf '.escapeshellarg($this->blobRoot));
    }
});

it('enables only discovered Inbox and Sent by default', function () {
    ($this->run)();

    expect($this->account->remoteFolders()->where('sync_enabled', true)->orderBy('role')->pluck('role')->all())->toBe(['inbox', 'sent'])
        ->and($this->account->remoteFolders()->where('sync_enabled', false)->count())->toBe(3);
});

it('shows received Inbox mail only in Inbox and remote Sent mail only in Sent, without duplicates', function () {
    $this->imap->add(mime('Received', 'a@other.test', 'me@example.test', 'r1'));
    $this->imap->add(mime('Outgoing', 'me@example.test', 'a@other.test', 's1'), ['\\Seen'], '[Gmail]/Gesendet');
    // Same logical message visible in both synchronized folders.
    $both = mime('To myself', 'me@example.test', 'me@example.test', 'b1');
    $this->imap->add($both);
    $this->imap->add($both, [], '[Gmail]/Gesendet');
    ($this->run)();

    expect(sentIds($this, $this->user->id))->toBe(['Outgoing', 'To myself']);
    expect(DB::table('messages')->where('subject', 'To myself')->count())->toBe(1);
    expect(DB::table('messages')->where('subject', 'Outgoing')->value('direction'))->toBe('outbound')
        ->and(DB::table('messages')->where('subject', 'Received')->value('direction'))->toBe('inbound');
    $inbox = collect($this->getJson('/api/messages?view=inbox')->json('data'))->pluck('subject')->sort()->values()->all();
    expect($inbox)->toContain('Received')->not->toContain('Outgoing');
});

it('does not infer Sent from the From address when the remote folder is not Sent', function () {
    $this->imap->add(mime('Self addressed in inbox', 'me@example.test', 'me@example.test', 'x1'));
    ($this->run)();

    expect(sentIds($this, $this->user->id))->toBe([]);
});

it('threads a sent reply into the received conversation', function () {
    $this->imap->add(mime('Question', 'a@other.test', 'me@example.test', 'q1'));
    $this->imap->add(mime('Re: Question', 'me@example.test', 'a@other.test', 'q2', 'q1'), [], '[Gmail]/Gesendet');
    ($this->run)();

    $reply = (int) DB::table('messages')->where('subject', 'Re: Question')->value('id');
    $rows = $this->actingAs($this->user)->getJson("/api/messages/{$reply}/conversation")->assertOk()->json('data');
    expect(collect($rows)->pluck('subject')->all())->toBe(['Question', 'Re: Question']);
});

it('scopes Sent by account and isolates users', function () {
    $other = makeAccount($this->user, ['email_address' => 'two@example.test', 'display_name' => 'Two']);
    $this->imap->add(mime('First sent', 'me@example.test', 'a@other.test', 's1'), [], '[Gmail]/Gesendet');
    ($this->run)();
    $second = new FakeImapClient;
    $second->mailbox('[Gmail]/Gesendet', 'sent');
    $this->app->instance(ImapClient::class, $second);
    $second->add(mime('Second sent', 'two@example.test', 'a@other.test', 's2'), [], '[Gmail]/Gesendet');
    ($this->run)($other);

    expect(sentIds($this, $this->user->id, '&account_id='.$this->account->id))->toBe(['First sent'])
        ->and(DB::table('messages')->where('subject', 'Second sent')->value('mail_account_id'))->toBe($other->id);
    expect(sentIds($this, $this->user->id, '&account_id='.$other->id))->toBe(['Second sent']);
    expect(sentIds($this, User::factory()->create()->id))->toBe([]);
});

it('imports Sent history after an existing account enables it while new Sent mail stays first', function () {
    Queue::fake();
    $folder = $this->account->remoteFolders()->create([
        'raw_name' => '[Gmail]/Gesendet', 'name' => '[Gmail]/Gesendet', 'role' => 'sent', 'sync_enabled' => false, 'selectable' => true,
    ]);
    $this->imap->add(mime('Old sent', 'me@example.test', 'a@other.test', 'o1'), [], '[Gmail]/Gesendet');
    ($this->run)();
    expect(sentIds($this, $this->user->id))->toBe([]);

    $this->actingAs($this->user)->patchJson("/api/remote-folders/{$folder->id}", ['sync_enabled' => true])->assertOk();
    expect($this->account->refresh()->sync_requested_generation)->toBeGreaterThan(0);
    $this->imap->add(mime('Fresh sent', 'me@example.test', 'a@other.test', 'f1'), [], '[Gmail]/Gesendet');
    ($this->run)();
    ($this->run)();

    expect(sentIds($this, $this->user->id))->toBe(['Fresh sent', 'Old sent']);
    expect(DB::table('messages')->where('subject', 'Old sent')->count())->toBe(1);
});

it('does not enable a non-selectable folder and lets the user opt out of Sent', function () {
    ($this->run)();
    $sent = $this->account->remoteFolders()->where('role', 'sent')->first();
    $this->actingAs($this->user)->patchJson("/api/remote-folders/{$sent->id}", ['sync_enabled' => false])->assertOk()->assertJsonPath('data.sync_enabled', false);
    $sent->update(['selectable' => false]);
    $this->patchJson("/api/remote-folders/{$sent->id}", ['sync_enabled' => true])->assertUnprocessable();
    $this->actingAs(User::factory()->create())->patchJson("/api/remote-folders/{$sent->id}", ['sync_enabled' => true])->assertNotFound();
});

it('starts and stops watching Sent while the daemon keeps running', function () {
    Queue::fake();
    $clients = [];
    app()->bind(IdleClient::class, fn () => $clients[] = new FakeIdleClient);
    foreach (['inbox' => true, 'sent' => false, 'junk' => true] as $role => $on) {
        $this->account->remoteFolders()->create(['raw_name' => $role, 'name' => $role, 'role' => $role, 'sync_enabled' => $on, 'selectable' => true]);
    }
    $watcher = app(IdleWatcher::class);
    $watcher->reconcile();
    expect($watcher->connections())->toBe(1);
    $this->actingAs($this->user)->getJson('/api/accounts')->assertJsonPath('data.0.realtime.folders', ['inbox']);

    $sent = $this->account->remoteFolders()->where('role', 'sent')->first();
    $sent->update(['sync_enabled' => true]);
    $watcher->reconcile();
    expect($watcher->connections())->toBe(2);
    $this->getJson('/api/accounts')->assertJsonPath('data.0.realtime.folders', ['inbox', 'sent']);

    $sent->update(['sync_enabled' => false]);
    $watcher->reconcile();
    expect($watcher->connections())->toBe(1);
    $watcher->close();
});

it('migrates legacy disabled Sent folders without touching other roles or removed folders', function () {
    $this->account->remoteFolders()->create(['raw_name' => 'S', 'name' => 'S', 'role' => 'sent', 'sync_enabled' => false, 'selectable' => true]);
    $this->account->remoteFolders()->create(['raw_name' => 'T', 'name' => 'T', 'role' => 'trash', 'sync_enabled' => false, 'selectable' => true]);
    $this->account->remoteFolders()->create(['raw_name' => 'R', 'name' => 'R', 'role' => 'sent', 'sync_enabled' => false, 'selectable' => true, 'removed_at' => now()]);
    $this->account->update(['next_sync_at' => now()->addHour()]);

    (require base_path('database/migrations/2026_09_30_000001_enable_sent_folder_sync.php'))->up();

    expect($this->account->remoteFolders()->where('sync_enabled', true)->pluck('raw_name')->all())->toBe(['S']);
    expect($this->account->refresh()->next_sync_at->isFuture())->toBeFalse();
});
