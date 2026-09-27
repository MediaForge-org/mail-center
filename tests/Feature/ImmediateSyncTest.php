<?php

use App\Connectors\Imap\ImapClient;
use App\Jobs\SyncAccountJob;
use App\Models\User;
use App\Sync\AccountSyncLock;
use App\Sync\SyncRequests;
use App\Sync\SyncRunner;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImapClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->root = sys_get_temp_dir().'/mailcenter-immediate-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $this->root]);
    $this->imap = new FakeImapClient;
    app()->instance(ImapClient::class, $this->imap);
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('accepts manual intent despite a stale Redis unique key and coalesces queued clicks', function () {
    $cache = app('cache')->store('array');
    app()->instance(Repository::class, $cache);
    $cache->lock('laravel_unique_job:'.SyncAccountJob::class.':'.$this->account->id, 900)->get();
    foreach (range(1, 5) as $i) {
        $this->actingAs($this->user)->postJson("/api/accounts/{$this->account->id}/sync")
            ->assertAccepted()->assertJsonPath('data.generation', 1)->assertJsonPath('data.state', 'queued');
    }
    Queue::assertPushed(SyncAccountJob::class, 1);
    Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->queue === 'sync-high');
    expect($this->account->refresh()->sync_requested_generation)->toBe(1);
});

it('promotes scheduled queued work and either delivery satisfies the same generation', function () {
    $requests = app(SyncRequests::class);
    $requests->request($this->account->id);
    $requests->request($this->account->id, 'manual');
    Queue::assertPushed(SyncAccountJob::class, 2);
    $this->imap->add(FakeImapClient::raw('Promoted'));
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    (new SyncAccountJob($this->account->id, 'manual'))->handle(app(SyncRunner::class));
    expect(DB::table('sync_runs')->count())->toBe(1)
        ->and(DB::table('sync_runs')->value('trigger'))->toBe('manual')
        ->and($this->account->refresh()->sync_completed_generation)->toBe(1);
});

it('retains exactly one follow up for repeated manual and idle events during IO', function () {
    $requests = app(SyncRequests::class);
    $requests->request($this->account->id);
    $this->imap->add(FakeImapClient::raw('During IO'));
    $this->imap->onFetch = function () use ($requests) {
        foreach (['manual', 'idle', 'manual', 'scheduled', 'manual'] as $trigger) {
            $requests->request($this->account->id, $trigger);
        }
    };
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect($this->account->refresh()->sync_requested_generation)->toBe(2)
        ->and($this->account->sync_completed_generation)->toBe(1);
    Queue::assertPushed(SyncAccountJob::class, 2);
    $this->imap->onFetch = null;
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect($this->account->refresh()->sync_completed_generation)->toBe(2);
});

it('keeps intent under advisory lock contention and redelivers lost/crashed work', function () {
    app(SyncRequests::class)->request($this->account->id, 'manual');
    $lock = app(AccountSyncLock::class);
    expect($lock->acquire($this->account))->toBeTrue();
    expect(app(SyncRunner::class)->run($this->account->id, 'manual', true))->toBe('locked');
    expect($this->account->refresh()->sync_completed_generation)->toBe(0);
    $lock->release($this->account);
    // A dead process claimed but never acknowledged generation 1; Redis delivery is gone.
    $this->account->update(['sync_started_generation' => 1, 'sync_dispatched_at' => now()->subMinute()]);
    Queue::fake();
    app(SyncRequests::class)->recover();
    Queue::assertPushed(SyncAccountJob::class, 1);
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect($this->account->refresh()->sync_completed_generation)->toBe(1);
});

it('retries recoverable quarantined mail once on manual action without waiting two minutes', function () {
    $this->imap->add(FakeImapClient::raw('Temporary failure'));
    $this->imap->fetchFailures[1] = new RuntimeException('Synthetic error');
    app(SyncRunner::class)->run($this->account->id);
    expect(DB::table('messages')->count())->toBe(0);
    unset($this->imap->fetchFailures[1]);
    app(SyncRequests::class)->request($this->account->id, 'manual');
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect(DB::table('messages')->count())->toBe(1)
        ->and(DB::table('sync_failures')->value('status'))->toBe('resolved');
});

it('commits new Sent mail before historical Inbox work and records phase timestamps', function () {
    $this->imap->mailbox('Sent', 'sent');
    app(SyncRunner::class)->run($this->account->id);
    $inbox = $this->account->remoteFolders()->where('role', 'inbox')->firstOrFail();
    $sent = $this->account->remoteFolders()->where('role', 'sent')->firstOrFail();
    $inbox->update(['backfill_completed_at' => null, 'backfill_low_uid' => 2, 'sync_high_uid' => 1]);
    $sent->update(['sync_enabled' => true, 'uidvalidity' => 1000, 'sync_high_uid' => 0, 'backfill_low_uid' => 1]);
    $this->imap->add(FakeImapClient::raw('Historical Inbox'));
    $this->imap->add(FakeImapClient::raw('New Sent'), [], 'Sent');
    $this->imap->calls = [];
    $this->imap->onFetch = function () {
        if (DB::table('messages')->where('subject', 'Historical Inbox')->exists()) {
            expect(DB::table('messages')->where('subject', 'New Sent')->exists())->toBeTrue();
        }
    };
    app(SyncRequests::class)->request($this->account->id, 'manual');
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect(DB::table('messages')->orderBy('id')->pluck('subject')->all())->toBe(['New Sent', 'Historical Inbox']);
    $timings = json_decode(DB::table('sync_runs')->orderByDesc('id')->value('timings'), true);
    expect($timings)->toHaveKeys(['worker_started', 'lock_acquired', 'connect_started', 'connected', 'first_fetch_started', 'first_ingestion_started', 'first_message_committed', 'run_finished']);
});

it('immediately wakes a manual request after a crashed claim without waiting for the sweeper', function (int $requested) {
    $this->account->update(['sync_requested_generation' => $requested, 'sync_started_generation' => 1, 'sync_completed_generation' => 0, 'sync_request_trigger' => 'manual']);
    $accepted = app(SyncRequests::class)->request($this->account->id, 'manual');
    expect($accepted['state'])->toBe('queued')->and($accepted['generation'])->toBe(2);
    Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->queue === 'sync-high');
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect($this->account->refresh()->sync_completed_generation)->toBe(2);
})->with([1, 2]);

it('redelivers an unacknowledged queued manual request after Redis delivery loss', function () {
    app(SyncRequests::class)->request($this->account->id, 'manual');
    Queue::fake(); // the transport message was lost
    $this->travel(2)->seconds();
    $accepted = app(SyncRequests::class)->request($this->account->id, 'manual');
    expect($accepted['generation'])->toBe(1)->and($accepted['state'])->toBe('queued');
    Queue::assertPushed(SyncAccountJob::class, 1);
});

it('uses normal UIDVALIDITY recovery for an IDLE-triggered request', function () {
    $raw = FakeImapClient::raw('IDLE reset');
    $this->imap->add($raw);
    app(SyncRunner::class)->run($this->account->id);
    $id = DB::table('messages')->value('id');
    $this->imap->resetUidValidity('INBOX', 2000);
    $this->imap->add($raw);
    app(SyncRequests::class)->request($this->account->id, 'idle');
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect(DB::table('messages')->count())->toBe(1)->and(DB::table('messages')->value('id'))->toBe($id)
        ->and(DB::table('message_locations')->whereNull('removed_at')->value('uidvalidity'))->toBe(2000);
});

it('observes remote flag notifications immediately while preserving local choices with mirroring off', function () {
    $this->imap->add(FakeImapClient::raw('Remote flags'));
    app(SyncRunner::class)->run($this->account->id);
    $this->imap->mailboxes['INBOX']['messages'][1]['flags'] = ['\\Seen'];
    app(SyncRequests::class)->request($this->account->id, 'idle');
    (new SyncAccountJob($this->account->id))->handle(app(SyncRunner::class));
    expect(DB::table('messages')->value('remote_seen'))->toBeTrue()
        ->and(DB::table('messages')->value('is_read'))->toBeFalse();
});
