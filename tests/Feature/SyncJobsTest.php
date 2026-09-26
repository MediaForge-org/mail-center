<?php

use App\Accounts\Credentials\CredentialVault;
use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Jobs\SyncAccountJob;
use App\Jobs\TestConnectionJob;
use App\Models\User;
use App\Sync\SyncRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImapClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->blobRoot = sys_get_temp_dir().'/mailcenter-test-'.bin2hex(random_bytes(4));
    config(['mailcenter.blobs.root' => $this->blobRoot]);
    $this->imap = new FakeImapClient;
    $this->app->instance(ImapClient::class, $this->imap);
    $this->user = User::factory()->create();
});

afterEach(function () {
    if (is_dir($this->blobRoot)) {
        exec('rm -rf '.escapeshellarg($this->blobRoot));
    }
});

it('configures the sync job for the sync queue, one attempt, and per-account uniqueness', function () {
    $job = new SyncAccountJob(42);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->connection)->toBe('mail_sync')->and($job->queue)->toBe('sync')
        ->and($job->tries)->toBe(1)->and($job->timeout)->toBeLessThan(660)
        ->and($job->uniqueId())->toBe('42');
    expect(config('queue.connections.mail_sync.retry_after'))->toBeGreaterThan($job->timeout);
});

it('runs a queued sync job end to end', function () {
    $account = makeAccount($this->user);
    $this->imap->add(FakeImapClient::raw('Queued'));

    (new SyncAccountJob($account->id, 'manual'))->handle(app(SyncRunner::class));

    expect(DB::table('messages')->count())->toBe(1);
    expect(DB::table('sync_runs')->value('trigger'))->toBe('manual');
});

it('dispatches only accounts that are due and eligible', function () {
    Queue::fake();
    $due = makeAccount($this->user, ['email_address' => 'due@example.test']);
    makeAccount($this->user, ['email_address' => 'future@example.test', 'next_sync_at' => now()->addHour()]);
    makeAccount($this->user, ['email_address' => 'off@example.test', 'sync_enabled' => false]);
    makeAccount($this->user, ['email_address' => 'disabled@example.test', 'enabled' => false]);
    makeAccount($this->user, ['email_address' => 'authfail@example.test', 'sync_status' => 'auth_failed']);
    makeAccount($this->user, ['email_address' => 'removed@example.test'])->delete();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SyncAccountJob::class, 1);
    Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->accountId === $due->id);
});

it('recovers a crashed run and makes the account due again', function () {
    Queue::fake();
    $account = makeAccount($this->user, ['sync_status' => 'syncing', 'next_sync_at' => null]);
    DB::table('sync_runs')->insert(['mail_account_id' => $account->id, 'trigger' => 'scheduled', 'started_at' => now()->subMinutes(30)]);

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    expect(DB::table('sync_runs')->value('status'))->toBe('failed')->and(DB::table('sync_runs')->value('error_code'))->toBe('stale_run');
    Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->accountId === $account->id);
});

it('schedules the dispatcher every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'sync:dispatch-due'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

describe('connection tests', function () {
    beforeEach(function () {
        $this->payload = ['host' => 'imap.example.test', 'port' => 993, 'security' => 'tls', 'username' => 'me', 'password' => 'test-only-secret-1'];
    });

    it('queues an encrypted test, runs it, and clears the stored settings', function () {
        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/api/accounts/test-connection', $this->payload)
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data.id');
        Queue::assertPushed(TestConnectionJob::class, fn ($job) => $job->testId === $id);
        // Only the id travels through Redis; the stored settings are ciphertext.
        expect(DB::table('connection_tests')->value('encrypted_settings'))->not->toContain('test-only-secret-1')->not->toContain('imap.example.test');

        (new TestConnectionJob($id))->handle($this->imap, app(CredentialVault::class));

        $this->actingAs($this->user)->getJson("/api/connection-tests/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result_code', 'ok');
        expect(DB::table('connection_tests')->value('encrypted_settings'))->toBeNull();
        expect($this->imap->calls)->toContain('CONNECT')->toContain('LIST')->toContain('DISCONNECT');
    });

    it('reports connection failures with sanitized text', function () {
        Queue::fake();
        $this->imap->connectFailure = new ImapFailure(ImapFailure::AUTH, 'raw server said test-only-secret-1');
        $id = $this->actingAs($this->user)->postJson('/api/accounts/test-connection', $this->payload)->json('data.id');

        (new TestConnectionJob($id))->handle($this->imap, app(CredentialVault::class));

        $response = $this->actingAs($this->user)->getJson("/api/connection-tests/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result_code', 'auth_failed');
        expect($response->getContent())->not->toContain('test-only-secret-1');
        expect(json_encode(DB::table('connection_tests')->get()))->not->toContain('test-only-secret-1');
    });

    it('does not run expired tests and hides tests from other users', function () {
        Queue::fake();
        $id = $this->actingAs($this->user)->postJson('/api/accounts/test-connection', $this->payload)->json('data.id');
        DB::table('connection_tests')->update(['expires_at' => now()->subMinute()]);

        (new TestConnectionJob($id))->handle($this->imap, app(CredentialVault::class));
        expect($this->imap->connects)->toBe(0);
        $this->actingAs($this->user)->getJson("/api/connection-tests/{$id}")->assertJsonPath('data.status', 'expired');

        $other = User::factory()->create();
        $this->actingAs($other)->getJson("/api/connection-tests/{$id}")->assertNotFound();
    });

    it('validates host and port and rate limits tests', function () {
        Queue::fake();
        $this->actingAs($this->user)->postJson('/api/accounts/test-connection', [...$this->payload, 'port' => 22])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/api/accounts/test-connection', [...$this->payload, 'host' => 'a b'])->assertUnprocessable();
        foreach (range(1, 8) as $i) {
            $this->actingAs($this->user)->postJson('/api/accounts/test-connection', $this->payload);
        }
        $this->actingAs($this->user)->postJson('/api/accounts/test-connection', $this->payload)->assertStatus(429);
    });
});
