<?php

use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('scopes explicit account mail and binds cursors to that account', function () {
    $user = User::factory()->create();
    $a = makeAccount($user, ['enabled' => false, 'sync_enabled' => false]);
    $b = makeAccount($user);
    $foreign = makeAccount(User::factory()->create());
    $deleted = makeAccount($user, ['deleted_at' => now()]);
    foreach (range(1, 3) as $i) {
        listMessage($user, $a->id, "A$i", '2026-01-01', ['is_done' => true, 'remote_status' => 'removed']);
        listMessage($user, $b->id, "B$i", '2026-01-01');
    }
    listMessage($user, $a->id, 'Trash', '2026-01-02', ['deleted_at' => now()]);
    $this->actingAs($user);
    $first = $this->getJson("/api/messages?account_id=$a->id&limit=1")->assertOk();
    $first->assertJsonPath('data.0.mail_account_id', $a->id);
    $cursor = urlencode($first->json('next_cursor'));
    $second = $this->getJson("/api/messages?account_id=$a->id&limit=1&cursor=$cursor")->assertOk();
    expect($second->json('data.0.id'))->not->toBe($first->json('data.0.id'));
    $this->getJson("/api/messages?account_id=$b->id&cursor=$cursor")->assertUnprocessable();
    $this->getJson("/api/messages?cursor=$cursor")->assertUnprocessable();
    $unified = urlencode($this->getJson('/api/messages?limit=1')->json('next_cursor'));
    $this->getJson("/api/messages?account_id=$a->id&cursor=$unified")->assertUnprocessable();
    foreach ([$foreign->id, $deleted->id, 999999] as $id) {
        $this->getJson("/api/messages?account_id=$id")->assertNotFound();
    }
    $this->getJson("/api/messages?account_id=$a->id&view=inbox")->assertUnprocessable();
    $this->getJson('/api/messages?account_id=invalid')->assertUnprocessable();
});

it('counts messages with identical list predicates and a bounded query count', function () {
    $this->getJson('/api/mailbox-counts')->assertUnauthorized();
    $user = User::factory()->create();
    $a = makeAccount($user, ['sync_enabled' => false]);
    $disabled = makeAccount($user, ['enabled' => false]);
    $empty = makeAccount($user);
    $deleted = makeAccount($user, ['deleted_at' => now()]);
    $foreignUser = User::factory()->create();
    $foreign = makeAccount($foreignUser);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $archive = SystemFolders::idFor($user->id, 'archive');
    $id = listMessage($user, $a->id, 'Inbox unread', '2026-01-01', ['folder_id' => $inbox]);
    listMessage($user, $a->id, 'Inbox read', '2026-01-01', ['folder_id' => $inbox, 'is_read' => true]);
    listMessage($user, $a->id, 'Done', '2026-01-01', ['folder_id' => $inbox, 'is_done' => true]);
    listMessage($user, $a->id, 'Removed', '2026-01-01', ['folder_id' => $inbox, 'remote_status' => 'removed']);
    listMessage($user, $a->id, 'Outside', '2026-01-01', ['folder_id' => $archive]);
    listMessage($user, $a->id, 'Trash', '2026-01-01', ['deleted_at' => now()]);
    listMessage($user, $disabled->id, 'Disabled', '2026-01-01', ['folder_id' => $inbox]);
    listMessage($user, $deleted->id, 'Deleted', '2026-01-01');
    listMessage($foreignUser, $foreign->id, 'Foreign', '2026-01-01');
    $folder = $a->remoteFolders()->create(['name' => 'INBOX', 'raw_name' => 'INBOX', 'role' => 'inbox']);
    foreach ([1, 2] as $uid) {
        DB::table('message_locations')->insert([
            'message_id' => $id, 'mail_account_id' => $a->id, 'remote_folder_id' => $folder->id,
            'uidvalidity' => 1, 'uid' => $uid, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->getJson('/api/mailbox-counts')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    $response->assertJsonPath('views.all', ['total' => 5, 'unread' => 4])
        ->assertJsonPath('views.inbox', ['total' => 2, 'unread' => 1])
        ->assertJsonPath('views.unread', ['total' => 3, 'unread' => 3])
        ->assertJsonPath("accounts.$a->id", ['total' => 5, 'unread' => 4])
        ->assertJsonPath("accounts.$disabled->id", ['total' => 1, 'unread' => 1])
        ->assertJsonPath("accounts.$empty->id", ['total' => 0, 'unread' => 0])
        ->assertJsonMissingPath("accounts.$foreign->id")->assertJsonMissingPath("accounts.$deleted->id");
    foreach (['all', 'inbox', 'unread'] as $view) {
        expect(count($this->getJson("/api/messages?view=$view")->json('data')))->toBe($response->json("views.$view.total"));
    }
    foreach (range(1, 15) as $i) {
        makeAccount($user);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/mailbox-counts')->assertOk();
    expect(count(DB::getQueryLog()))->toBe($queryCount)->toBeLessThanOrEqual(8);
    DB::disableQueryLog();
});
