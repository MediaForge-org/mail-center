<?php

use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('requires authentication for every folder route', function () {
    $this->getJson('/api/folders')->assertUnauthorized();
    $this->postJson('/api/folders', ['name' => 'X'])->assertUnauthorized();
    $this->patchJson('/api/folders/1', ['name' => 'X'])->assertUnauthorized();
    $this->putJson('/api/folders/order', ['folder_ids' => [1]])->assertUnauthorized();
    $this->deleteJson('/api/folders/1')->assertUnauthorized();
});

it('lists a user\'s folders ordered by position, seeded system and default folders included', function () {
    $user = User::factory()->create();

    $folders = $this->actingAs($user)->getJson('/api/folders')->assertOk()->json('data');

    expect(array_column($folders, 'name'))->toBe(['Inbox', 'Sent', 'Archive', 'Reloads', 'Support', 'Withdrawals', 'Verification', 'Done']);
    expect(array_column($folders, 'system_role'))->toBe(['inbox', 'sent', 'archive', null, null, null, null, null]);
});

it('creates a custom folder at the next position and rejects invalid or duplicate names', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $created = $this->postJson('/api/folders', ['name' => 'Projects'])->assertCreated()->json('data');
    expect($created['system_role'])->toBeNull();
    expect($created['position'])->toBe(8);

    $this->postJson('/api/folders', ['name' => ''])->assertUnprocessable();
    $this->postJson('/api/folders', ['name' => '   '])->assertUnprocessable();
    $this->postJson('/api/folders', ['name' => "bad\x07name"])->assertUnprocessable();
    $this->postJson('/api/folders', ['name' => str_repeat('a', 81)])->assertUnprocessable();
    $this->postJson('/api/folders', ['name' => 'projects'])->assertUnprocessable(); // case-insensitive collision
    $this->postJson('/api/folders', ['name' => 'Support'])->assertUnprocessable(); // collides with seeded default
});

it('renames a custom or system folder without changing system_role, and enforces ownership', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $inbox = SystemFolders::idFor($alice->id, 'inbox');
    $custom = $this->actingAs($alice)->postJson('/api/folders', ['name' => 'Projects'])->json('data.id');

    $this->patchJson("/api/folders/{$inbox}", ['name' => 'Primary'])->assertOk()
        ->assertJsonPath('data.system_role', 'inbox')->assertJsonPath('data.name', 'Primary');
    $this->patchJson("/api/folders/{$custom}", ['name' => 'Projects']) // no-op rename
        ->assertOk()->assertJsonPath('data.name', 'Projects');
    $this->patchJson("/api/folders/{$custom}", ['name' => 'Primary'])->assertUnprocessable();

    $this->actingAs($bob)->patchJson("/api/folders/{$custom}", ['name' => 'Stolen'])->assertNotFound();
    $this->patchJson('/api/folders/999999', ['name' => 'Ghost'])->assertNotFound();
});

it('reorders a user\'s folders in one transaction and rejects malformed payloads', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $ids = collect($this->getJson('/api/folders')->json('data'))->pluck('id')->all();
    $reversed = array_reverse($ids);

    $this->putJson('/api/folders/order', ['folder_ids' => $reversed])->assertOk();
    expect(DB::table('folders')->where('user_id', $user->id)->orderBy('position')->pluck('id')->all())->toBe($reversed);

    $this->putJson('/api/folders/order', ['folder_ids' => array_slice($ids, 1)])->assertUnprocessable(); // missing one
    $this->putJson('/api/folders/order', ['folder_ids' => array_merge($ids, [$ids[0]])])->assertUnprocessable(); // duplicate
    $this->putJson('/api/folders/order', ['folder_ids' => array_merge($ids, [999999])])->assertUnprocessable(); // foreign id + extra

    $other = User::factory()->create();
    $foreignId = SystemFolders::idFor($other->id, 'inbox');
    $this->putJson('/api/folders/order', ['folder_ids' => array_merge(array_slice($ids, 1), [$foreignId])])->assertUnprocessable();
});

it('deletes a custom folder by moving its messages to Inbox, never deleting mail, and refuses system folders', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $custom = $this->actingAs($user)->postJson('/api/folders', ['name' => 'Temp'])->json('data.id');
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $a = listMessage($user, $account->id, 'A', '2026-09-26 12:00:00+00', ['folder_id' => $custom]);
    $b = listMessage($user, $account->id, 'B', '2026-09-26 12:00:00+00', ['folder_id' => $custom]);
    listMessage($user, $account->id, 'C', '2026-09-26 12:00:00+00', ['folder_id' => $inbox]);

    $result = $this->deleteJson("/api/folders/{$custom}")->assertOk()->json('data');
    expect($result['moved_count'])->toBe(2);
    expect(DB::table('messages')->whereIn('id', [$a, $b])->pluck('folder_id')->all())->toBe([$inbox, $inbox]);
    expect(DB::table('messages')->count())->toBe(3); // no email was deleted
    expect(DB::table('folders')->where('id', $custom)->exists())->toBeFalse();
    $audit = DB::table('audit_events')->where('action', 'folder.deleted')->first();
    expect(json_decode($audit->context, true)['moved_count'])->toBe(2);

    foreach (['inbox', 'sent', 'archive'] as $role) {
        $this->deleteJson('/api/folders/'.SystemFolders::idFor($user->id, $role))->assertUnprocessable();
    }
    $this->deleteJson('/api/folders/999999')->assertNotFound();
});

it('does not leak foreign folder existence through 404s', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $bobsFolder = $this->actingAs($bob)->postJson('/api/folders', ['name' => 'Secret'])->json('data.id');

    $this->actingAs($alice);
    expect(collect($this->getJson('/api/folders')->assertOk()->json('data'))->pluck('id'))->not->toContain($bobsFolder);
    $this->patchJson("/api/folders/{$bobsFolder}", ['name' => 'Stolen'])->assertNotFound();
    $this->deleteJson("/api/folders/{$bobsFolder}")->assertNotFound();
});
