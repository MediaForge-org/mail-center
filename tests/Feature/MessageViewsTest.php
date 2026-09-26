<?php

use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates separate system folders for each new user without exposing folder editing', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    foreach ([$alice, $bob] as $user) {
        expect(DB::table('folders')->where('user_id', $user->id)->orderBy('position')->pluck('system_role')->all())
            ->toBe(['inbox', 'sent', 'archive']);
    }
    expect(SystemFolders::idFor($alice->id, 'inbox'))->not->toBe(SystemFolders::idFor($bob->id, 'inbox'));
    $this->actingAs($alice)->postJson('/api/folders', ['name' => 'Custom'])->assertNotFound();
    $this->patchJson('/api/folders/1', ['name' => 'Changed'])->assertNotFound();
});

it('applies all, inbox and unread predicates with local folders and owner scope', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $account = makeAccount($alice, ['sync_enabled' => false]);
    $disabled = makeAccount($alice, ['email_address' => 'disabled@example.test', 'enabled' => false]);
    $bobAccount = makeAccount($bob);
    $inbox = SystemFolders::idFor($alice->id, 'inbox');
    $archive = SystemFolders::idFor($alice->id, 'archive');
    $date = '2026-09-26 12:00:00+00';

    $inboxUnread = listMessage($alice, $account->id, 'Inbox unread', $date, ['folder_id' => $inbox]);
    $inboxRead = listMessage($alice, $account->id, 'Inbox read', $date, ['folder_id' => $inbox, 'is_read' => true]);
    $archiveUnread = listMessage($alice, $account->id, 'Archive unread', $date, ['folder_id' => $archive]);
    $done = listMessage($alice, $account->id, 'Done', $date, ['folder_id' => $inbox, 'is_done' => true]);
    $removed = listMessage($alice, $account->id, 'Removed', $date, ['folder_id' => $inbox, 'remote_status' => 'removed']);
    listMessage($alice, $account->id, 'Deleted', $date, ['folder_id' => $inbox, 'deleted_at' => now()]);
    listMessage($alice, $disabled->id, 'Disabled', $date, ['folder_id' => $inbox]);
    listMessage($bob, $bobAccount->id, 'Bob', $date, ['folder_id' => SystemFolders::idFor($bob->id, 'inbox')]);

    $this->actingAs($alice);
    $all = $this->getJson('/api/messages')->assertOk();
    expect(array_column($all->json('data'), 'id'))->toEqualCanonicalizing([$inboxUnread, $inboxRead, $archiveUnread, $done, $removed]);
    expect($this->getJson('/api/messages?view=all')->json('data'))->toBe($all->json('data'));
    expect(array_column($this->getJson('/api/messages?view=inbox')->assertOk()->json('data'), 'id'))
        ->toEqualCanonicalizing([$inboxUnread, $inboxRead]);
    expect(array_column($this->getJson('/api/messages?view=unread')->assertOk()->json('data'), 'id'))
        ->toEqualCanonicalizing([$inboxUnread, $archiveUnread, $done]);
});

it('rejects unknown views and unrelated query filters', function () {
    $this->actingAs(User::factory()->create());
    $this->getJson('/api/messages?view=starred')->assertUnprocessable()->assertJsonValidationErrors('view');
    $this->getJson('/api/messages?folder_id=1')->assertUnprocessable()->assertJsonValidationErrors('query');
    $this->getJson('/api/messages?view[]=inbox')->assertUnprocessable()->assertJsonValidationErrors('view');
});

it('keeps cursors within each view and paginates identical dates deterministically', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $date = '2026-09-26 12:00:00+00';
    $ids = [];
    foreach (range(1, 5) as $number) {
        $ids[] = listMessage($user, $account->id, "Mail {$number}", $date, ['folder_id' => $inbox]);
    }
    $expected = array_reverse($ids);
    $this->actingAs($user);

    foreach (['all', 'inbox', 'unread'] as $view) {
        $first = $this->getJson("/api/messages?view={$view}&limit=2")->assertOk();
        $cursor = urlencode($first->json('next_cursor'));
        $second = $this->getJson("/api/messages?view={$view}&limit=2&cursor={$cursor}")->assertOk();
        $third = $this->getJson("/api/messages?view={$view}&limit=2&cursor=".urlencode($second->json('next_cursor')))->assertOk();
        expect(array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
            array_column($third->json('data'), 'id'),
        ))->toBe($expected);
        expect($third->json('next_cursor'))->toBeNull();
        foreach (array_diff(['all', 'inbox', 'unread'], [$view]) as $other) {
            $this->getJson("/api/messages?view={$other}&cursor={$cursor}")
                ->assertUnprocessable()->assertJsonValidationErrors('cursor');
        }
    }
});

it('backfills existing messages from the first committed location and does not move them again', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $sentRemote = $account->remoteFolders()->create(['raw_name' => 'Sent', 'name' => 'Sent', 'role' => 'sent']);
    $inboxRemote = $account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $date = '2026-09-26 12:00:00+00';
    $message = listMessage($user, $account->id, 'Legacy', $date);
    $withoutLocation = listMessage($user, $account->id, 'No location', $date);

    DB::table('message_locations')->insert([
        ['message_id' => $message, 'mail_account_id' => $account->id, 'remote_folder_id' => $sentRemote->id,
            'uidvalidity' => 1, 'uid' => 1, 'flags' => '[]', 'first_seen_at' => now(), 'last_seen_at' => now()],
        ['message_id' => $message, 'mail_account_id' => $account->id, 'remote_folder_id' => $inboxRemote->id,
            'uidvalidity' => 1, 'uid' => 1, 'flags' => '[]', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()],
    ]);

    SystemFolders::backfillMissing();
    expect((int) DB::table('messages')->where('id', $message)->value('folder_id'))->toBe(SystemFolders::idFor($user->id, 'sent'));
    expect((int) DB::table('messages')->where('id', $withoutLocation)->value('folder_id'))->toBe(SystemFolders::idFor($user->id, 'archive'));
    SystemFolders::backfillMissing();
    expect((int) DB::table('messages')->where('id', $message)->value('folder_id'))->toBe(SystemFolders::idFor($user->id, 'sent'));
});

it('migrates an existing M2 message without re-ingesting it', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $remote = $account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $message = listMessage($user, $account->id, 'Before folders', '2026-09-26 12:00:00+00');
    DB::table('message_locations')->insert([
        'message_id' => $message, 'mail_account_id' => $account->id, 'remote_folder_id' => $remote->id,
        'uidvalidity' => 1, 'uid' => 1, 'flags' => '[]', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $dedupe = DB::table('messages')->where('id', $message)->value('dedupe_key');

    // Recreate the M2 table shape inside this test transaction, then run the real migration.
    DB::statement('DROP INDEX messages_inbox_feed_idx');
    DB::statement('DROP INDEX messages_unread_feed_idx');
    DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_folder_owner_fk');
    Schema::table('messages', fn ($table) => $table->dropColumn('folder_id'));
    Schema::drop('folders');
    $migration = require database_path('migrations/2026_09_26_000001_create_system_folders.php');
    $migration->up();

    expect(DB::table('messages')->count())->toBe(1);
    expect(DB::table('messages')->where('id', $message)->value('dedupe_key'))->toBe($dedupe);
    expect((int) DB::table('messages')->where('id', $message)->value('folder_id'))->toBe(SystemFolders::idFor($user->id, 'inbox'));
    expect(DB::table('folders')->where('user_id', $user->id)->count())->toBe(3);
});
