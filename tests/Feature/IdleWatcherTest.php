<?php

use App\Connectors\Imap\ImapFailure;
use App\Models\User;
use App\Sync\Idle\IdleClient;
use App\Sync\Idle\IdleWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdleClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->account = makeAccount(User::factory()->create());
    foreach (['inbox', 'sent', 'other'] as $role) {
        $this->account->remoteFolders()->create([
            'raw_name' => $role, 'name' => $role, 'role' => $role, 'sync_enabled' => true, 'selectable' => true,
        ]);
    }
    $this->clients = [];
    app()->bind(IdleClient::class, function () {
        return $this->clients[] = new FakeIdleClient;
    });
    $this->watcher = app(IdleWatcher::class);
});

afterEach(function () {
    $this->watcher->close();
});

it('watches only enabled Inbox and Sent with durable duplicate prevention and coalesced events', function () {
    $this->watcher->reconcile();
    $this->watcher->reconcile();
    expect($this->watcher->connections())->toBe(2);
    expect(array_column($this->clients, 'folder'))->toBe(['inbox', 'sent']);
    $duplicate = app(IdleWatcher::class);
    $duplicate->reconcile();
    expect($duplicate->connections())->toBe(0);
    foreach ($this->clients as $client) {
        $client->notify();
    }
    $this->watcher->wait(0);
    expect($this->account->refresh()->sync_requested_generation)->toBe(1);
    expect(DB::table('sync_watchers')->where('state', 'watching')->count())->toBe(2);
    $this->actingAs($this->account->user)->getJson('/api/accounts')->assertJsonPath('data.0.realtime.state', 'watching');
});

it('disconnects when paused and automatically resumes; credential changes reconnect', function () {
    $this->watcher->reconcile();
    $this->account->update(['sync_enabled' => false]);
    $this->watcher->reconcile();
    expect($this->watcher->connections())->toBe(0)->and($this->clients[0]->closed)->toBeTrue();
    $this->account->update(['sync_enabled' => true]);
    $this->watcher->reconcile();
    expect($this->watcher->connections())->toBe(1);
    $this->account->credentials()->update(['ciphertext' => 'different-test-ciphertext']);
    $this->watcher->reconcile();
    expect($this->clients[1]->closed)->toBeTrue()->and($this->watcher->connections())->toBe(1);
});

it('recovers expired leases and fences the old watcher before notifications', function () {
    $this->watcher->reconcile();
    DB::table('sync_watchers')->update(['lease_until' => now()->subSecond()]);
    $replacement = app(IdleWatcher::class);
    $replacement->reconcile();
    expect($replacement->connections())->toBe(1);
    $this->clients[0]->notify();
    $this->watcher->wait(0);
    expect($this->watcher->connections())->toBe(0);
    expect(DB::table('sync_watchers')->where('state', 'watching')->count())->toBe(1);
    $replacement->close();
});

it('falls back without IDLE capability and reconnects with bounded persisted backoff', function () {
    app()->bind(IdleClient::class, function () {
        $client = new FakeIdleClient;
        $client->supported = false;

        return $this->clients[] = $client;
    });
    $this->watcher->reconcile();
    expect($this->watcher->connections())->toBe(0)
        ->and(DB::table('sync_watchers')->value('state'))->toBe('unsupported');
    app()->bind(IdleClient::class, function () {
        return $this->clients[] = new FakeIdleClient;
    });
    $this->watcher->reconcile(); // Sent can still be watched.
    $client = end($this->clients);
    $client->failure = new ImapFailure(ImapFailure::TRANSIENT, 'synthetic');
    $client->notify();
    $this->watcher->wait(0);
    expect(DB::table('sync_watchers')->where('state', 'backoff')->count())->toBe(1);
    $this->travel(3)->seconds();
    $this->watcher->reconcile();
    expect($this->watcher->connections())->toBe(1);
});

it('stops on auth failure and stops all sessions when the account is removed', function () {
    $this->watcher->reconcile();
    $this->clients[0]->failure = new ImapFailure(ImapFailure::AUTH, 'synthetic');
    $this->clients[0]->notify();
    $this->watcher->wait(0);
    $this->watcher->reconcile();
    expect($this->account->refresh()->sync_status)->toBe('auth_failed')->and($this->watcher->connections())->toBe(0);
    $this->account->update(['sync_status' => 'idle']);
    $this->account->delete();
    $this->watcher->reconcile();
    expect($this->watcher->connections())->toBe(0);
});
