<?php

use App\Models\MailAccount;
use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function place(MailAccount $account, int $message, $folder, int $uid, int $validity = 1, bool $removed = false): void
{
    DB::table('message_locations')->insert([
        'message_id' => $message, 'mail_account_id' => $account->id, 'remote_folder_id' => $folder->id,
        'uidvalidity' => $validity, 'uid' => $uid, 'flags' => '[]', 'first_seen_at' => now(), 'last_seen_at' => now(),
        'removed_at' => $removed ? now() : null, 'removed_reason' => $removed ? 'expunged' : null,
    ]);
}

/** 27 messages: 25 received/Inbox-only and 2 with active Sent membership (one of those also in Inbox). */
function canonicalMailbox(User $user): array
{
    $account = makeAccount($user);
    $inbox = $account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $sent = $account->remoteFolders()->create(['raw_name' => '[Gmail]/Gesendet', 'name' => '[Gmail]/Gesendet', 'role' => 'sent']);
    $ids = [];
    foreach (range(1, 25) as $i) {
        $ids[$i] = listMessage($user, $account->id, "Received {$i}", '2026-01-01', $i === 1 ? ['from_address' => $account->email_address, 'direction' => 'inbound'] : []);
        place($account, $ids[$i], $inbox, $i);
    }
    // A removed Sent location does not make a message Sent.
    place($account, $ids[2], $sent, 100, 1, true);
    // Sent 1: two physical Sent locations (different UIDVALIDITY generations) are still one logical message.
    $sent1 = listMessage($user, $account->id, 'Sent one', '2026-01-02', ['direction' => 'outbound']);
    place($account, $sent1, $sent, 1);
    place($account, $sent1, $sent, 1, 2);
    // Sent 2: the same logical message is visible in Inbox and Sent.
    $sent2 = listMessage($user, $account->id, 'Sent two', '2026-01-03');
    place($account, $sent2, $inbox, 26);
    place($account, $sent2, $sent, 2);

    SystemFolders::backfillMissing();

    return [$account, $sent1, $sent2];
}

it('counts Sent with exactly the predicate that selects Sent rows', function () {
    $user = User::factory()->create();
    [$account, $sent1, $sent2] = canonicalMailbox($user);
    // Another user's Sent mail never leaks in.
    $other = User::factory()->create();
    $otherAccount = makeAccount($other, ['email_address' => 'other@example.test']);
    $otherSent = $otherAccount->remoteFolders()->create(['raw_name' => 'Sent', 'name' => 'Sent', 'role' => 'sent']);
    place($otherAccount, listMessage($other, $otherAccount->id, 'Other sent', '2026-01-04'), $otherSent, 1);

    $this->actingAs($user);
    $counts = $this->getJson('/api/mailbox-counts')->assertOk();
    $counts->assertJsonPath('views.sent.total', 2)->assertJsonPath('views.all.total', 27)
        ->assertJsonPath('views.inbox.total', 26)->assertJsonPath("accounts.{$account->id}.total", 27);

    $rows = collect($this->getJson('/api/messages?view=sent&limit=50')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($rows)->toBe(collect([$sent1, $sent2])->sort()->values()->all());
    $scoped = $this->getJson("/api/messages?view=sent&account_id={$account->id}&limit=50")->assertOk()->json('data');
    expect($scoped)->toHaveCount(2);
    expect($counts->json('views.sent.total'))->toBe(count($rows))->not->toBe($counts->json("accounts.{$account->id}.total"))
        ->not->toBe($counts->json('views.all.total'));

    // Received mail with the account's own address in From is never Sent.
    expect(collect($rows)->contains(DB::table('messages')->where('subject', 'Received 1')->value('id')))->toBeFalse();
    // Cross-user isolation in both directions.
    $this->actingAs($other)->getJson('/api/mailbox-counts')->assertJsonPath('views.sent.total', 1)->assertJsonPath('views.all.total', 1);
});

it('follows a message out of Sent when its only Sent location is removed', function () {
    $user = User::factory()->create();
    [$account, $sent1] = canonicalMailbox($user);
    DB::table('message_locations')->where('message_id', $sent1)->update(['removed_at' => now(), 'removed_reason' => 'expunged']);

    $this->actingAs($user)->getJson('/api/mailbox-counts')->assertJsonPath('views.sent.total', 1);
    expect($this->getJson('/api/messages?view=sent')->json('data'))->toHaveCount(1);
});

it('reports live watcher health on the cheap version endpoint, never mere capability', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = $account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox', 'sync_enabled' => true]);
    $this->actingAs($user)->getJson('/api/changes?since=0')->assertOk()->assertJsonPath('realtime', false);

    DB::table('sync_watchers')->insert([
        'remote_folder_id' => $inbox->id, 'mail_account_id' => $account->id, 'fingerprint' => 'x', 'owner' => (string) Str::uuid(),
        'state' => 'watching', 'lease_until' => now()->addSeconds(20), 'attempts' => 0,
    ]);
    $this->getJson('/api/changes?since=0')->assertJsonPath('realtime', true);
    $this->getJson('/api/accounts')->assertJsonPath('data.0.realtime.state', 'watching')->assertJsonPath('data.0.realtime.roles', ['inbox']);

    DB::table('sync_watchers')->update(['lease_until' => now()->subSecond()]);
    $this->getJson('/api/changes?since=0')->assertJsonPath('realtime', false);
    $this->getJson('/api/accounts')->assertJsonPath('data.0.realtime.state', 'polling');
    // Another user's healthy watcher does not make this user's page realtime.
    DB::table('sync_watchers')->update(['lease_until' => now()->addSeconds(20)]);
    $this->actingAs(User::factory()->create())->getJson('/api/changes?since=0')->assertJsonPath('realtime', false);
});

it('flags an imminent commit on the version endpoint only while a sync request is pending', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $this->actingAs($user)->getJson('/api/changes?since=0')->assertJsonPath('active', false);
    $account->update(['sync_requested_generation' => 2, 'sync_completed_generation' => 1]);
    $this->getJson('/api/changes?since=0')->assertJsonPath('active', true);
    $account->update(['sync_completed_generation' => 2]);
    $this->getJson('/api/changes?since=0')->assertJsonPath('active', false);
    $account->update(['sync_requested_generation' => 3, 'sync_enabled' => false]);
    $this->getJson('/api/changes?since=0')->assertJsonPath('active', false);
});
