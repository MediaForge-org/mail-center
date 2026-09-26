<?php

use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Ingestion\MessageIngestor;
use App\Jobs\PushRemoteFlagChangesJob;
use App\Jobs\SyncAccountJob;
use App\Models\User;
use App\Organization\OrganizationService;
use App\Sync\AccountSyncLock;
use App\Sync\SyncAborted;
use App\Sync\SyncRunner;
use App\Writeback\SeenWriteback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeImapClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/mailcenter-seen-'.bin2hex(random_bytes(5));
    config(['mailcenter.blobs.root' => $this->root]);
    Queue::fake();
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
    $this->imap = new FakeImapClient;
    $this->app->instance(ImapClient::class, $this->imap);
    $this->org = app(OrganizationService::class);
    $this->imap->add(FakeImapClient::raw('Seen test'));
    app(SyncRunner::class)->run($this->account->id);
    $this->id = DB::table('messages')->value('id');
    $this->location = DB::table('message_locations')->value('id');
    $this->actingAs($this->user);
});
afterEach(function () {
    File::deleteDirectory($this->root);
});

it('mutates only owned local read state idempotently and refreshes exact views and counts', function () {
    $revision = DB::table('messages')->value('local_revision');
    $url = "/api/messages/{$this->id}/read";
    $this->patchJson($url, ['is_read' => true])->assertOk()->assertJsonPath('data.is_read', true);
    $this->patchJson($url, ['is_read' => true])->assertOk();
    $this->assertDatabaseHas('messages', ['id' => $this->id, 'is_read' => true, 'local_revision' => $revision + 1, 'is_starred' => false]);
    expect(DB::table('messages')->value('local_updated_at'))->not->toBeNull();
    $this->getJson('/api/messages?view=unread')->assertJsonCount(0, 'data');
    $this->getJson('/api/mailbox-counts')->assertJsonPath('views.all.total', 1)->assertJsonPath('views.all.unread', 0)
        ->assertJsonPath('views.inbox.total', 1)->assertJsonPath('views.inbox.unread', 0)->assertJsonPath("accounts.{$this->account->id}.unread", 0);
    $this->patchJson($url, ['is_read' => false])->assertOk();
    $this->getJson('/api/messages?view=unread')->assertJsonCount(1, 'data');
    $this->getJson('/api/mailbox-counts')->assertJsonPath('views.all.unread', 1)->assertJsonPath('views.inbox.unread', 1)
        ->assertJsonPath("accounts.{$this->account->id}.total", 1)->assertJsonPath("accounts.{$this->account->id}.unread", 1);
    Queue::assertNotPushed(PushRemoteFlagChangesJob::class);
    expect(DB::table('remote_flag_changes')->count())->toBe(0);
    $this->account->update(['enabled' => false]);
    $this->patchJson($url, ['is_read' => true])->assertOk();
    $this->actingAs(User::factory()->create())->patchJson($url, ['is_read' => false])->assertNotFound();
    $this->actingAs($this->user);
    DB::table('messages')->where('id', $this->id)->update(['deleted_at' => now()]);
    $this->patchJson($url, ['is_read' => false])->assertNotFound();
});

it('creates generation-bound after-commit work and supersedes older pending intent', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    Queue::assertPushed(PushRemoteFlagChangesJob::class, fn ($job) => $job->afterCommit === true); // Laravel's testing transaction manager treats the outer fixture transaction as committed.
    $this->org->setRead($this->user->id, $this->id, false);
    $rows = DB::table('remote_flag_changes')->orderBy('id')->get();
    expect($rows->pluck('status')->all())->toBe(['superseded', 'pending']);
    expect($rows[1]->generation)->toBeGreaterThan($rows[0]->generation);
    expect($rows[1]->mirror_generation)->toBe(1);
    $this->org->setSeenMirroring($this->user->id, $this->account->id, false);
    expect(DB::table('remote_flag_changes')->where('status', 'pending')->count())->toBe(0);
    expect($this->account->fresh()->seen_mirror_generation)->toBe(2);
});

it('batches verified Seen additions and removals without a transaction around network IO', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->imap->onStore = function () {
        expect(DB::transactionLevel())->toBe(1);
    }; // RefreshDatabase outer test transaction only
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->calls)->toContain('UID STORE 1 +FLAGS.SILENT (\\Seen)');
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('done');
    expect(DB::table('messages')->value('remote_seen'))->toBeTrue();
    $this->org->setRead($this->user->id, $this->id, false);
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->calls)->toContain('UID STORE 1 -FLAGS.SILENT (\\Seen)');
    expect(DB::table('remote_flag_changes')->where('status', 'done')->count())->toBe(2);
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
});

it('does not acknowledge a newer generation when an older STORE finishes', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->imap->onStore = function () {
        $this->org->setRead($this->user->id, $this->id, false);
        $this->imap->onStore = null;
    };
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
    expect(DB::table('remote_flag_changes')->orderBy('id')->pluck('status')->all())->toBe(['superseded', 'pending']);
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->mailboxes['INBOX']['messages'][1]['flags'])->not->toContain('\\Seen');
    expect(DB::table('remote_flag_changes')->orderByDesc('id')->value('status'))->toBe('done');
});

it('refuses stale UIDVALIDITY and schedules synchronization', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->imap->mailboxes['INBOX']['uidvalidity'] = 2000;
    app(SeenWriteback::class)->run($this->account->id);
    expect(implode(' ', $this->imap->calls))->not->toContain('STORE');
    expect(DB::table('remote_flag_changes')->value('last_error'))->toBe('uidvalidity_changed');
    expect($this->account->fresh()->next_sync_at)->not->toBeNull();
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
});

it('requires post-STORE verification, persists backoff and retains terminal intent', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->imap->ignoreStore = true;
    app(SeenWriteback::class)->run($this->account->id);
    $row = DB::table('remote_flag_changes')->first();
    expect($row->status)->toBe('pending')->and($row->last_error)->toBe('verification_failed');
    expect(Carbon\Carbon::parse($row->next_attempt_at)->isFuture())->toBeTrue();
    DB::table('remote_flag_changes')->update(['attempts' => 9, 'next_attempt_at' => now()]);
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('failed');
    $this->getJson("/api/messages/{$this->id}")->assertJsonPath('data.read_writeback', 'failed')->assertJsonPath('data.is_read', true);
    $this->org->setRead($this->user->id, $this->id, true); // explicit retry, not a local rollback
    expect(DB::table('remote_flag_changes')->where('status', 'pending')->count())->toBe(1);
});

it('pauses on authentication failure and retains local state', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->imap->connectFailure = new ImapFailure(ImapFailure::AUTH, 'secret must not escape');
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->account->fresh()->sync_status)->toBe('auth_failed');
    expect(DB::table('remote_flag_changes')->value('last_error'))->toBe('auth_failed');
    $before = $this->imap->connects;
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->connects)->toBe($before);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
});

it('uses the synchronization lock and sweeps lost jobs and expired leases', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $lock = app(AccountSyncLock::class);
    expect($lock->acquire($this->account))->toBeTrue();
    $before = $this->imap->connects;
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->connects)->toBe($before);
    $lock->release($this->account);
    Queue::fake();
    DB::table('remote_flag_changes')->update(['created_at' => now()->subMinutes(3)]);
    $this->artisan('writeback:sweep')->assertSuccessful();
    Queue::assertPushed(PushRemoteFlagChangesJob::class);
    Queue::fake();
    DB::table('remote_flag_changes')->update(['status' => 'processing', 'lease_expires_at' => now()->subSecond(), 'lease_token' => (string) Str::uuid()]);
    $this->artisan('writeback:sweep')->assertSuccessful();
    Queue::assertPushed(PushRemoteFlagChangesJob::class);
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('done');
});

it('reconciles complete observations on enable but protects unresolved and newer local intent', function () {
    $this->org->setRead($this->user->id, $this->id, true);
    $this->org->observeSeen($this->location, [], 0, 1);
    expect(DB::table('messages')->value('is_read'))->toBeTrue(); // disabled mirroring
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->travel(1)->seconds();
    $this->org->observeSeen($this->location, [], 1, 1);
    expect(DB::table('messages')->value('is_read'))->toBeFalse(); // adopts unchanged remote value
    $this->org->setRead($this->user->id, $this->id, true);
    $this->travel(1)->seconds();
    $this->org->observeSeen($this->location, [], 1, 1); // pre-action stale response
    $this->org->observeSeen($this->location, [], 1, 2);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
    DB::table('remote_flag_changes')->where('status', 'pending')->update(['status' => 'failed']);
    $this->travel(1)->seconds();
    $this->org->observeSeen($this->location, [], 1, 2);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
    DB::table('message_locations')->update(['removed_at' => now()]);
    app(MessageIngestor::class)->refreshRemoteSummary($this->id);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
});

it('writes all active copies but skips removed locations and uses one connection', function () {
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'Archive', 'name' => 'Archive', 'role' => 'archive', 'sync_enabled' => true, 'uidvalidity' => 1000]);
    $this->imap->mailbox('Archive');
    $this->imap->add(FakeImapClient::raw('Seen test'), [], 'Archive');
    app(MessageIngestor::class)->ingest($this->account, $folder, 1, 1000, FakeImapClient::raw('Seen test'), [], null);
    DB::table('message_locations')->insert([
        'message_id' => $this->id, 'mail_account_id' => $this->account->id, 'remote_folder_id' => $folder->id,
        'uidvalidity' => 1000, 'uid' => 99, 'removed_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $before = $this->imap->connects;
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->connects)->toBe($before + 1);
    expect($this->imap->mailboxes['INBOX']['messages'][1]['flags'])->toContain('\\Seen');
    expect($this->imap->mailboxes['Archive']['messages'][1]['flags'])->toContain('\\Seen');
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('done');
    expect(implode(' ', $this->imap->calls))->not->toContain('STORE 99');
});

it('adopts changed remote state through normal sync while off preserves local choices', function () {
    $this->org->setRead($this->user->id, $this->id, true);
    $this->travel(20)->minutes();
    app(SyncRunner::class)->run($this->account->id);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->travel(1)->seconds();
    app(SyncRunner::class)->run($this->account->id);
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
    $this->imap->mailboxes['INBOX']['messages'][1]['flags'] = ['\\Seen'];
    $this->travel(20)->minutes();
    app(SyncRunner::class)->run($this->account->id);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
    expect(DB::table('messages')->value('local_revision'))->toBe(3);
});

it('preserves an explicit same-value action made just after enabling mirroring', function () {
    $this->org->setRead($this->user->id, $this->id, true);
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    expect(DB::table('remote_flag_changes')->value('desired'))->toBeTrue();
    $this->travel(1)->seconds();
    app(SyncRunner::class)->run($this->account->id);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
});

it('requires complete location observations before initializing mirroring', function () {
    $folder = $this->account->remoteFolders()->first();
    $other = DB::table('message_locations')->insertGetId([
        'message_id' => $this->id, 'mail_account_id' => $this->account->id, 'remote_folder_id' => $folder->id,
        'uidvalidity' => 1000, 'uid' => 2, 'flags' => json_encode(['\\Seen']), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->observeSeen($this->location, [], 1, 0);
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
    $this->org->observeSeen($other, ['\\Seen'], 1, 0);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
});

it('keeps unknown-location intent pending and reports confirmed no-target intent', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    DB::table('message_locations')->update(['removed_at' => now()]);
    DB::table('messages')->update(['remote_status' => 'unknown']);
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('pending');
    expect(DB::table('remote_flag_changes')->value('attempts'))->toBe(0);
    DB::table('messages')->update(['remote_status' => 'removed']);
    DB::table('remote_flag_changes')->update(['next_attempt_at' => now()]);
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('superseded');
    expect($this->account->fresh()->seen_writeback_error)->toBe('no_target');
});

it('aborts when lock ownership is lost and never issues a stale batch', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $lock = Mockery::mock(AccountSyncLock::class);
    $lock->shouldReceive('acquire')->once()->andReturn(true);
    $lock->shouldReceive('assertHeld')->once()->andThrow(new SyncAborted('lost'));
    $lock->shouldReceive('release')->once();
    $this->app->instance(AccountSyncLock::class, $lock);
    $before = $this->imap->connects;
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->connects)->toBe($before);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
});

it('configures an isolated writeback queue with shared overlap protection', function () {
    $job = new PushRemoteFlagChangesJob($this->account->id);
    expect($job->connection)->toBe('writeback')->and($job->queue)->toBe('writeback');
    expect($job->timeout)->toBe(120)->and(config('queue.connections.writeback.retry_after'))->toBe(180);
    expect($job->middleware()[0]->key)->toBe((new SyncAccountJob($this->account->id))->middleware()[0]->key);
    expect(config('horizon.defaults.writeback-supervisor.timeout'))->toBe(120);
});

it('batches multiple compatible message intents in a single STORE', function () {
    $uid = $this->imap->add(FakeImapClient::raw('Another message'));
    app(SyncRunner::class)->run($this->account->id);
    $other = DB::table('messages')->where('id', '<>', $this->id)->value('id');
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->org->setRead($this->user->id, $other, true);
    app(SeenWriteback::class)->run($this->account->id);
    expect($this->imap->calls)->toContain("UID STORE 1,$uid +FLAGS.SILENT (\\Seen)");
    expect(DB::table('remote_flag_changes')->where('status', 'done')->count())->toBe(2);
});

it('persists transient connection backoff and wakes authentication-paused work after credentials change', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $this->imap->connectFailure = new ImapFailure(ImapFailure::TRANSIENT, 'do not expose');
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('pending');
    expect(DB::table('remote_flag_changes')->value('last_error'))->toBe(ImapFailure::TRANSIENT);
    DB::table('remote_flag_changes')->update(['next_attempt_at' => now()]);
    $this->imap->connectFailure = new ImapFailure(ImapFailure::AUTH, 'private');
    app(SeenWriteback::class)->run($this->account->id);
    Queue::fake();
    $this->putJson("/api/accounts/{$this->account->id}/credentials", ['password' => 'replacement', 'current_password' => 'password'])->assertOk();
    expect($this->account->fresh()->sync_status)->toBe('idle')->and($this->account->fresh()->seen_writeback_error)->toBeNull();
    Queue::assertPushed(PushRemoteFlagChangesJob::class);
    $this->imap->connectFailure = null;
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('done');
});

it('records partial completion and never acknowledges unverified active copies', function () {
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'Archive', 'name' => 'Archive', 'role' => 'archive', 'sync_enabled' => true, 'uidvalidity' => 1000]);
    $this->imap->mailbox('Archive');
    $this->imap->add(FakeImapClient::raw('Seen test'), [], 'Archive');
    app(MessageIngestor::class)->ingest($this->account, $folder, 1, 1000, FakeImapClient::raw('Seen test'), [], null);
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    $calls = 0;
    $this->imap->onStore = function () use (&$calls) {
        if (++$calls === 2) {
            throw new ImapFailure(ImapFailure::TRANSIENT, 'interrupted');
        }
    };
    app(SeenWriteback::class)->run($this->account->id);
    $row = DB::table('remote_flag_changes')->first();
    expect($row->status)->toBe('pending');
    expect(json_decode($row->completed_location_ids, true))->toHaveCount(1);
    DB::table('remote_flag_changes')->update(['next_attempt_at' => now()]);
    $this->imap->onStore = null;
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->value('status'))->toBe('done');
});

it('keeps newer intent safe when a crashed processing lease is reclaimed', function () {
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    DB::table('remote_flag_changes')->update(['status' => 'processing', 'lease_expires_at' => now()->subSecond()]);
    $this->org->setRead($this->user->id, $this->id, false);
    $this->travel(1)->seconds();
    app(SyncRunner::class)->run($this->account->id);
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('remote_flag_changes')->orderBy('id')->pluck('status')->all())->toBe(['superseded', 'done']);
    expect(implode(' ', $this->imap->calls))->not->toContain('+FLAGS.SILENT');
});

it('authorizes account opt-in and rejects inaccessible or invalid local mutations', function () {
    $url = "/api/messages/{$this->id}/read";
    $this->patchJson($url, ['is_read' => 'invalid'])->assertUnprocessable();
    $this->patchJson("/api/accounts/{$this->account->id}", ['write_back_seen' => true])->assertOk()->assertJsonPath('data.write_back_seen', true);
    $this->actingAs(User::factory()->create())->patchJson("/api/accounts/{$this->account->id}", ['write_back_seen' => false])->assertNotFound();
    $this->actingAs($this->user);
    $this->account->delete();
    $this->patchJson($url, ['is_read' => true])->assertNotFound();
});

it('invalidates pre-write observations after verified remote completion', function () {
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'Archive', 'name' => 'Archive', 'role' => 'archive', 'sync_enabled' => true, 'uidvalidity' => 1000]);
    $this->imap->mailbox('Archive');
    $this->imap->add(FakeImapClient::raw('Seen test'), [], 'Archive');
    app(MessageIngestor::class)->ingest($this->account, $folder, 1, 1000, FakeImapClient::raw('Seen test'), [], null);
    $locations = DB::table('message_locations')->orderBy('id')->pluck('id');
    $this->org->setSeenMirroring($this->user->id, $this->account->id, true);
    $this->org->setRead($this->user->id, $this->id, true);
    foreach ($locations as $id) {
        $this->org->observeSeen($id, [], 1, 1);
    }
    $this->travel(1)->seconds();
    app(SeenWriteback::class)->run($this->account->id);
    expect(DB::table('messages')->value('seen_reconciled_remote'))->toBeTrue();
    $this->travel(1)->seconds();
    $this->org->observeSeen($locations[0], [], 1, 1);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
    $this->org->observeSeen($locations[1], [], 1, 1);
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
});

it('requires authentication before accepting local read actions', function () {
    auth()->forgetGuards();
    $this->patchJson("/api/messages/{$this->id}/read", ['is_read' => true])->assertUnauthorized();
    expect(DB::table('messages')->value('is_read'))->toBeFalse();
});
