<?php

use App\Accounts\Credentials\CredentialVault;
use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function accountPayload(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Work', 'email_address' => 'me@example.test', 'host' => 'IMAP.Example.Test',
        'port' => 993, 'security' => 'tls', 'username' => 'me@example.test', 'password' => 'super-secret-pw-123',
    ], $overrides);
}

beforeEach(function () {
    Queue::fake();
    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
});

it('requires authentication for every account endpoint', function () {
    $this->getJson('/api/accounts')->assertUnauthorized();
    $this->postJson('/api/accounts', accountPayload())->assertUnauthorized();
    $this->postJson('/api/accounts/test-connection', accountPayload())->assertUnauthorized();
    $this->postJson('/api/accounts/1/sync')->assertUnauthorized();
    $this->patchJson('/api/remote-folders/1', ['sync_enabled' => true])->assertUnauthorized();
});

it('creates an account with an encrypted credential and never returns it', function () {
    $response = $this->actingAs($this->alice)->postJson('/api/accounts', accountPayload())
        ->assertCreated()
        ->assertJsonPath('data.incoming.host', 'imap.example.test')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.sync_status', 'never_synced');

    expect($response->getContent())->not->toContain('super-secret-pw-123')->not->toContain('ciphertext');
    $row = DB::table('mail_account_credentials')->first();
    expect($row->ciphertext)->not->toContain('super-secret-pw-123');
    expect(app(CredentialVault::class)->decrypt($row->ciphertext, $row->key_id)->password)->toBe('super-secret-pw-123');
    expect(json_encode(DB::table('mail_accounts')->first()))->not->toContain('super-secret-pw-123');

    $list = $this->actingAs($this->alice)->getJson('/api/accounts')->assertOk();
    expect($list->getContent())->not->toContain('super-secret-pw-123')->not->toContain('ciphertext');
    expect(json_encode(MailAccount::query()->first()->credentials->toArray()))->not->toContain('ciphertext');
    Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->trigger === 'initial');
});

it('validates input without echoing the password', function () {
    $response = $this->actingAs($this->alice)->postJson('/api/accounts', accountPayload([
        'host' => 'evil host/../', 'port' => 25, 'email_address' => 'nope', 'password' => 'leaky-password-value',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['host', 'port', 'email_address']);

    expect($response->getContent())->not->toContain('leaky-password-value');
    expect(MailAccount::count())->toBe(0);
    $this->actingAs($this->alice)->postJson('/api/accounts', accountPayload(['security' => 'none']))->assertJsonValidationErrors('security');
});

it('rejects a duplicate mailbox for the same user but allows it for another user', function () {
    $this->actingAs($this->alice)->postJson('/api/accounts', accountPayload())->assertCreated();
    $this->actingAs($this->alice)->postJson('/api/accounts', accountPayload(['email_address' => 'ME@example.test']))->assertUnprocessable();
    $this->actingAs($this->bob)->postJson('/api/accounts', accountPayload())->assertCreated();
});

it('scopes accounts to their owner on every endpoint', function () {
    $mine = makeAccount($this->alice);
    makeAccount($this->bob, ['email_address' => 'bob@example.test']);
    $folder = $mine->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);

    $list = $this->actingAs($this->alice)->getJson('/api/accounts')->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.id'))->toBe($mine->id);

    $this->actingAs($this->bob)->patchJson("/api/accounts/{$mine->id}", ['enabled' => false])->assertNotFound();
    $this->actingAs($this->bob)->deleteJson("/api/accounts/{$mine->id}", ['current_password' => 'password'])->assertNotFound();
    $this->actingAs($this->bob)->putJson("/api/accounts/{$mine->id}/credentials", ['password' => 'x', 'current_password' => 'password'])->assertNotFound();
    $this->actingAs($this->bob)->postJson("/api/accounts/{$mine->id}/sync")->assertNotFound();
    $this->actingAs($this->bob)->getJson("/api/accounts/{$mine->id}/sync-runs")->assertNotFound();
    $this->actingAs($this->bob)->getJson("/api/accounts/{$mine->id}/remote-folders")->assertNotFound();
    $this->actingAs($this->bob)->patchJson("/api/remote-folders/{$folder->id}", ['sync_enabled' => false])->assertNotFound();
    expect($folder->refresh()->sync_enabled)->toBeTrue();
});

it('updates settings and toggles synchronization', function () {
    $account = makeAccount($this->alice);
    $this->actingAs($this->alice)->patchJson("/api/accounts/{$account->id}", ['sync_enabled' => false])
        ->assertOk()->assertJsonPath('data.sync_enabled', false)->assertJsonPath('data.next_sync_at', null);
    $this->actingAs($this->alice)->patchJson("/api/accounts/{$account->id}", ['sync_enabled' => true])
        ->assertOk()->assertJsonPath('data.sync_enabled', true);
    expect($account->refresh()->next_sync_at)->not->toBeNull();
    $this->actingAs($this->alice)->patchJson("/api/accounts/{$account->id}", ['sync_interval_seconds' => 5])->assertUnprocessable();
    $this->actingAs($this->alice)->patchJson("/api/accounts/{$account->id}", ['display_name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.short_label', 'RE');
});

it('replaces credentials write-only, requires the current password, and clears auth_failed', function () {
    $account = makeAccount($this->alice);
    $account->update(['sync_status' => 'auth_failed', 'next_sync_at' => null, 'last_error_code' => 'auth_failed', 'consecutive_failures' => 3]);

    $this->actingAs($this->alice)->putJson("/api/accounts/{$account->id}/credentials", ['password' => 'new-pw', 'current_password' => 'wrong'])
        ->assertUnprocessable();
    $response = $this->actingAs($this->alice)->putJson("/api/accounts/{$account->id}/credentials", ['password' => 'new-pw-value', 'current_password' => 'password'])
        ->assertOk();
    expect($response->getContent())->not->toContain('new-pw-value');

    $account->refresh();
    expect($account->sync_status)->toBe('idle')->and($account->consecutive_failures)->toBe(0)->and($account->next_sync_at)->not->toBeNull();
    $row = $account->credentials()->first();
    expect(app(CredentialVault::class)->decrypt($row->ciphertext, $row->key_id)->password)->toBe('new-pw-value');
});

it('removes an account locally, drops its secret, and requires the current password', function () {
    $account = makeAccount($this->alice);

    $this->actingAs($this->alice)->deleteJson("/api/accounts/{$account->id}", ['current_password' => 'nope'])->assertUnprocessable();
    expect(MailAccount::count())->toBe(1);

    $this->actingAs($this->alice)->deleteJson("/api/accounts/{$account->id}", ['current_password' => 'password'])->assertOk();
    expect(MailAccount::count())->toBe(0)->and(MailAccount::withTrashed()->count())->toBe(1);
    expect(DB::table('mail_account_credentials')->count())->toBe(0);
    $this->actingAs($this->alice)->getJson('/api/accounts')->assertJsonCount(0, 'data');
});

it('queues a manual sync and respects auth failure, disabled sync, and backoff', function () {
    $account = makeAccount($this->alice, ['next_sync_at' => now()->addHour()]);

    $this->actingAs($this->alice)->postJson("/api/accounts/{$account->id}/sync")->assertAccepted();
    Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->accountId === $account->id && $job->trigger === 'manual');
    expect($account->refresh()->next_sync_at->lte(now()))->toBeTrue();

    $account->update(['sync_status' => 'backing_off', 'next_sync_at' => now()->addMinutes(5)]);
    $this->actingAs($this->alice)->postJson("/api/accounts/{$account->id}/sync")->assertStatus(409);

    $account->update(['sync_status' => 'auth_failed', 'next_sync_at' => null]);
    $this->actingAs($this->alice)->postJson("/api/accounts/{$account->id}/sync")->assertStatus(409);

    $account->update(['sync_status' => 'idle', 'sync_enabled' => false]);
    $this->actingAs($this->alice)->postJson("/api/accounts/{$account->id}/sync")->assertStatus(409);
    Queue::assertPushed(SyncAccountJob::class, 1);
});

it('exposes status, errors, quarantine counts, folders and run history', function () {
    $account = makeAccount($this->alice);
    $account->update(['sync_status' => 'error', 'last_error_code' => 'tls_invalid', 'last_error_message' => 'The server certificate could not be verified.']);
    $folder = $account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    DB::table('sync_failures')->insert(['remote_folder_id' => $folder->id, 'uidvalidity' => 1, 'uid' => 4, 'error_code' => 'too_large',
        'last_error' => 'x', 'status' => 'manual', 'first_failed_at' => now(), 'last_failed_at' => now()]);
    DB::table('sync_runs')->insert(['mail_account_id' => $account->id, 'trigger' => 'manual', 'started_at' => now(), 'status' => 'success', 'stats' => '{"new":2}']);

    $this->actingAs($this->alice)->getJson('/api/accounts')->assertOk()
        ->assertJsonPath('data.0.last_error_code', 'tls_invalid')
        ->assertJsonPath('data.0.quarantined_message_count', 1);
    $this->actingAs($this->alice)->getJson("/api/accounts/{$account->id}/sync-runs")->assertOk()->assertJsonPath('data.0.stats.new', 2);
    $this->actingAs($this->alice)->patchJson("/api/remote-folders/{$folder->id}", ['sync_enabled' => false])->assertOk()->assertJsonPath('data.sync_enabled', false);
    $this->actingAs($this->alice)->getJson("/api/accounts/{$account->id}/remote-folders")->assertOk()->assertJsonPath('data.0.name', 'INBOX');
});
