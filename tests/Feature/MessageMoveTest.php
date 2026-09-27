<?php

use App\Models\User;
use App\Organization\OrganizationService;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('moves a single message to another local folder without touching remote state or other flags', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $target = $this->actingAs($user)->postJson('/api/folders', ['name' => 'Projects'])->json('data.id');
    $id = listMessage($user, $account->id, 'Move me', '2026-09-26 12:00:00+00', [
        'folder_id' => $inbox, 'is_read' => true, 'is_starred' => true, 'is_important' => true,
        'is_done' => true, 'remote_status' => 'present', 'thread_id' => null,
    ]);

    $response = $this->patchJson("/api/messages/{$id}/folder", ['folder_id' => $target])->assertOk();
    expect($response->json('data.folder_id'))->toBe($target);
    $row = DB::table('messages')->where('id', $id)->first();
    expect((int) $row->folder_id)->toBe($target)
        ->and($row->is_read)->toBeTrue()->and($row->is_starred)->toBeTrue()
        ->and($row->is_important)->toBeTrue()->and($row->is_done)->toBeTrue()
        ->and($row->remote_status)->toBe('present')->and((int) $row->local_revision)->toBe(1);

    $audit = DB::table('audit_events')->where('action', 'messages.moved')->first();
    expect($audit->subject_id)->toBe($id);
    expect(json_decode($audit->context, true))->toEqual(['from_folder_id' => $inbox, 'to_folder_id' => $target]);
});

it('is a no-op when moving a message into the folder it already occupies', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $id = listMessage($user, $account->id, 'Stay', '2026-09-26 12:00:00+00', ['folder_id' => $inbox]);

    $this->actingAs($user)->patchJson("/api/messages/{$id}/folder", ['folder_id' => $inbox])->assertOk();

    expect((int) DB::table('messages')->where('id', $id)->value('local_revision'))->toBe(0);
    expect(DB::table('audit_events')->where('action', 'messages.moved')->count())->toBe(0);
});

it('rejects moving a message into a foreign, deleted, or nonexistent folder, and moving a foreign message', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $account = makeAccount($alice);
    $inbox = SystemFolders::idFor($alice->id, 'inbox');
    $bobsInbox = SystemFolders::idFor($bob->id, 'inbox');
    $id = listMessage($alice, $account->id, 'Mine', '2026-09-26 12:00:00+00', ['folder_id' => $inbox]);
    $bobsAccount = makeAccount($bob, ['email_address' => 'bob@example.test']);
    $bobsMessage = listMessage($bob, $bobsAccount->id, 'Bobs', '2026-09-26 12:00:00+00', ['folder_id' => $bobsInbox]);

    $this->actingAs($alice);
    $this->patchJson("/api/messages/{$id}/folder", ['folder_id' => $bobsInbox])->assertNotFound();
    $this->patchJson("/api/messages/{$id}/folder", ['folder_id' => 999999])->assertNotFound();
    $this->patchJson("/api/messages/{$bobsMessage}/folder", ['folder_id' => $inbox])->assertNotFound();

    $deletedFolder = $this->postJson('/api/folders', ['name' => 'Gone'])->json('data.id');
    $this->deleteJson("/api/folders/{$deletedFolder}");
    $this->patchJson("/api/messages/{$id}/folder", ['folder_id' => $deletedFolder])->assertNotFound();
});

it('refuses to move a locally deleted message through the normal folder route', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $target = $this->actingAs($user)->postJson('/api/folders', ['name' => 'Projects'])->json('data.id');
    $id = listMessage($user, $account->id, 'Gone', '2026-09-26 12:00:00+00', ['folder_id' => $inbox, 'deleted_at' => now()]);

    $this->patchJson("/api/messages/{$id}/folder", ['folder_id' => $target])->assertNotFound();
});

it('moves an entire conversation explicitly and never a single-message endpoint implicitly', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $threadId = DB::table('threads')->insertGetId(['mail_account_id' => $account->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    $target = $this->actingAs($user)->postJson('/api/folders', ['name' => 'Projects'])->json('data.id');
    $first = listMessage($user, $account->id, 'Q', '2026-09-26 12:00:00+00', ['folder_id' => $inbox, 'thread_id' => $threadId]);
    $second = listMessage($user, $account->id, 'Re: Q', '2026-09-26 13:00:00+00', ['folder_id' => $inbox, 'thread_id' => $threadId]);
    $unrelated = listMessage($user, $account->id, 'Other', '2026-09-26 12:00:00+00', ['folder_id' => $inbox]);

    $response = $this->patchJson("/api/messages/{$first}/conversation/folder", ['folder_id' => $target])->assertOk();
    expect($response->json('data.moved_count'))->toBe(2);
    expect(DB::table('messages')->whereIn('id', [$first, $second])->pluck('folder_id')->all())->toBe([$target, $target]);
    expect((int) DB::table('messages')->where('id', $unrelated)->value('folder_id'))->toBe($inbox);

    $audit = DB::table('audit_events')->where('action', 'messages.moved')->whereJsonContains('context->conversation', true)->first();
    expect(json_decode($audit->context, true)['moved_count'])->toBe(2);

    // Single-message move never expands to the whole conversation.
    $back = $this->actingAs($user)->postJson('/api/folders', ['name' => 'Back'])->json('data.id');
    $this->patchJson("/api/messages/{$first}/folder", ['folder_id' => $back])->assertOk();
    expect((int) DB::table('messages')->where('id', $second)->value('folder_id'))->toBe($target);
});

it('never crosses accounts when moving a conversation', function () {
    $user = User::factory()->create();
    $accountA = makeAccount($user, ['email_address' => 'a@example.test']);
    $accountB = makeAccount($user, ['email_address' => 'b@example.test']);
    $threadA = DB::table('threads')->insertGetId(['mail_account_id' => $accountA->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    $inboxA = SystemFolders::idFor($user->id, 'inbox');
    $messageA = listMessage($user, $accountA->id, 'A', '2026-09-26 12:00:00+00', ['folder_id' => $inboxA, 'thread_id' => $threadA]);
    $messageB = listMessage($user, $accountB->id, 'B', '2026-09-26 12:00:00+00', ['folder_id' => $inboxA]);
    $target = $this->actingAs($user)->postJson('/api/folders', ['name' => 'Projects'])->json('data.id');

    $this->patchJson("/api/messages/{$messageA}/conversation/folder", ['folder_id' => $target])->assertOk();

    expect((int) DB::table('messages')->where('id', $messageB)->value('folder_id'))->toBe($inboxA);
});

it('serializes two concurrent moves of the same message deterministically', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $folderX = app(OrganizationService::class)->createFolder($user->id, 'X')['id'];
    $folderY = app(OrganizationService::class)->createFolder($user->id, 'Y')['id'];
    $id = listMessage($user, $account->id, 'Race', '2026-09-26 12:00:00+00', ['folder_id' => $inbox]);

    app(OrganizationService::class)->moveMessage($user->id, $id, $folderX);
    app(OrganizationService::class)->moveMessage($user->id, $id, $folderY);

    expect((int) DB::table('messages')->where('id', $id)->value('folder_id'))->toBe($folderY);
    expect((int) DB::table('messages')->where('id', $id)->value('local_revision'))->toBe(2);
    expect(DB::table('audit_events')->where('action', 'messages.moved')->count())->toBe(2);
});

it('recovers a folder deletion race against a concurrent move into that folder', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $doomed = app(OrganizationService::class)->createFolder($user->id, 'Doomed')['id'];
    $id = listMessage($user, $account->id, 'Contested', '2026-09-26 12:00:00+00', ['folder_id' => $inbox]);

    app(OrganizationService::class)->deleteFolder($user->id, $doomed);

    $this->actingAs($user)->patchJson("/api/messages/{$id}/folder", ['folder_id' => $doomed])->assertNotFound();
    expect((int) DB::table('messages')->where('id', $id)->value('folder_id'))->toBe($inbox);
});
