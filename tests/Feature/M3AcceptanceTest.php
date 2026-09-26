<?php

use App\Conversations\Threader;
use App\Jobs\SanitizeMaintenance;
use App\Messages\EmailHtml;
use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('reads system folders with matching counts without hiding done or remotely removed mail', function () {
    $user = User::factory()->create();
    $account = makeAccount($user, ['enabled' => false, 'sync_enabled' => false]);
    foreach (['sent', 'archive'] as $view) {
        $id = listMessage($user, $account->id, $view, '2026-01-01', ['folder_id' => SystemFolders::idFor($user->id, $view), 'is_done' => true, 'remote_status' => 'removed']);
        $this->actingAs($user)->getJson("/api/messages?view={$view}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/messages?view={$view}&account_id={$account->id}")->assertOk()->assertJsonPath('data.0.id', $id);
        $account->update(['enabled' => true]);
        $this->getJson('/api/mailbox-counts')->assertJsonPath("views.{$view}.total", 1);
        $account->update(['enabled' => false]);
    }
});

it('preserves the combined All Mail invariant and exact counts through local and remote changes', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $id = listMessage($user, $account->id, 'Invariant', '2026-01-01');
    $this->actingAs($user);
    foreach ([['is_read' => true], ['is_read' => false], ['remote_seen' => true], ['is_done' => true], ['remote_status' => 'removed']] as $change) {
        DB::table('messages')->where('id', $id)->update($change);
        $this->getJson('/api/messages')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/mailbox-counts')->assertJsonPath('views.all.total', 1);
    }
    $account->update(['sync_enabled' => false]);
    $this->getJson('/api/mailbox-counts')->assertJsonPath('views.all.total', 1)->assertJsonPath('views.all.unread', 1)->assertJsonPath('views.unread.total', 0);
    $account->update(['enabled' => false]);
    $this->getJson('/api/mailbox-counts')->assertJsonPath('views.all.total', 0)->assertJsonPath("accounts.{$account->id}.total", 1);
    DB::table('messages')->where('id', $id)->update(['deleted_at' => now()]);
    $this->getJson("/api/messages?account_id={$account->id}")->assertJsonCount(0, 'data');
    $account->delete();
    $this->getJson("/api/messages?account_id={$account->id}")->assertNotFound();
});

it('assigns newest-first children and late parents to one account-isolated chronological conversation', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $other = makeAccount($user);
    $child = listMessage($user, $account->id, 'Child', '2026-02-01', ['message_id_header' => 'child@example.test', 'in_reply_to' => 'parent@example.test']);
    $parent = listMessage($user, $account->id, 'Parent', '2026-01-01', ['message_id_header' => 'parent@example.test', 'remote_status' => 'removed']);
    $foreignAccount = listMessage($user, $other->id, 'Other account', '2026-01-01', ['message_id_header' => 'parent@example.test']);
    $threader = app(Threader::class);
    foreach ([$child, $parent, $foreignAccount, $parent] as $id) {
        $threader->assign($id);
    }
    expect(DB::table('threads')->count())->toBe(2);
    $this->actingAs($user)->getJson("/api/messages/{$child}/conversation")->assertOk()->assertJsonPath('data.0.id', $parent)->assertJsonPath('data.1.id', $child)->assertJsonCount(2, 'data');
    DB::table('messages')->where('id', $parent)->update(['deleted_at' => now()]);
    $this->getJson("/api/messages/{$child}/conversation")->assertJsonCount(1, 'data');
    $this->getJson("/api/messages/{$parent}/conversation")->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson("/api/messages/{$child}/conversation")->assertNotFound();
});

it('merges multiple parent threads but splits an ambiguous late duplicate and ignores malformed self references', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $ids = [];
    foreach (['a', 'b'] as $name) {
        $ids[] = listMessage($user, $account->id, $name, '2026-01-01', ['message_id_header' => "$name@example.test"]);
    }
    $ids[] = listMessage($user, $account->id, 'bridge', '2026-01-02', ['message_id_header' => 'bridge@example.test', 'references' => '["a@example.test","b@example.test"]']);
    foreach ($ids as $id) {
        app(Threader::class)->assign($id);
    }
    expect(DB::table('messages')->distinct()->count('thread_id'))->toBe(1);
    $duplicate = listMessage($user, $account->id, 'duplicate', '2026-01-03', ['message_id_header' => 'a@example.test']);
    app(Threader::class)->assign($duplicate);
    foreach (DB::table('thread_repairs')->pluck('message_id') as $id) {
        app(Threader::class)->assign($id);
    }
    expect(DB::table('messages')->where('id', $ids[0])->value('thread_id'))->not->toBe(DB::table('messages')->where('id', $duplicate)->value('thread_id'));
    expect(DB::table('messages')->where('id', $ids[1])->value('thread_id'))->toBe(DB::table('messages')->where('id', $ids[2])->value('thread_id'));
    $bad = listMessage($user, $account->id, 'bad', '2026-01-04', ['message_id_header' => 'not valid', 'in_reply_to' => 'not valid']);
    app(Threader::class)->assign($bad);
    expect(Threader::identifier('not valid'))->toBeNull();
    $self = listMessage($user, $account->id, 'self', '2026-01-04', ['message_id_header' => 'self@example.test', 'in_reply_to' => 'self@example.test']);
    app(Threader::class)->assign($self);
    expect(DB::table('messages')->where('id', $self)->value('thread_id'))->not->toBeNull();
});

it('paginates conversation metadata without bodies and rejects cross-message cursors', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $first = listMessage($user, $account->id, 'First', '2026-01-01', ['message_id_header' => 'root@example.test']);
    app(Threader::class)->assign($first);
    for ($i = 0; $i < 55; $i++) {
        $id = listMessage($user, $account->id, "Reply {$i}", '2026-01-02', ['in_reply_to' => 'root@example.test']);
        app(Threader::class)->assign($id);
    }
    $page = $this->actingAs($user)->getJson("/api/messages/{$first}/conversation")->assertOk()->assertJsonCount(50, 'data');
    $cursor = urlencode($page->json('next_cursor'));
    $this->getJson("/api/messages/{$first}/conversation?cursor={$cursor}")->assertJsonCount(6, 'data')->assertJsonPath('next_cursor', null);
    $this->getJson("/api/messages/{$id}/conversation?cursor={$cursor}")->assertUnprocessable();
    expect(array_keys($page->json('data.0')))->toBe(['id', 'subject', 'from_name', 'from_address', 'sort_date', 'is_read', 'remote_status']);
});

it('invalidates ingestion, bodies, account disable and delete atomically and scopes cheap polls', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $this->getJson('/api/changes')->assertUnauthorized();
    $version = $this->actingAs($user)->getJson('/api/changes')->assertOk()->json('version');
    $id = listMessage($user, $account->id, 'New mail', '2026-01-01');
    $next = $this->getJson("/api/changes?since={$version}")->assertJsonPath('invalidate', true)->json('version');
    DB::beginTransaction();
    DB::table('messages')->where('id', $id)->update(['deleted_at' => now()]);
    DB::rollBack();
    $this->getJson("/api/changes?since={$next}")->assertJsonPath('invalidate', false);
    $account->update(['enabled' => false]);
    $next = $this->getJson("/api/changes?since={$next}")->assertJsonPath('invalidate', true)->json('version');
    DB::table('message_bodies')->insert(['message_id' => $id, 'text_plain' => 'Body']);
    $this->getJson("/api/changes?since={$next}")->assertJsonPath('invalidate', true);
    $this->actingAs(User::factory()->create())->getJson('/api/changes')->assertJsonPath('version', '1');
});

it('recovers durable maintenance after lost dispatch and fences live leases', function () {
    Queue::fake();
    $this->artisan('messages:sanitize-html')->assertSuccessful();
    $run = DB::table('maintenance_runs')->first();
    $this->artisan('messages:sanitize-html')->assertSuccessful();
    expect(DB::table('maintenance_runs')->count())->toBe(1);
    DB::table('maintenance_runs')->where('id', $run->id)->update(['status' => 'processing', 'lease_expires_at' => now()->addMinute()]);
    (new SanitizeMaintenance($run->id))->handle(new EmailHtml);
    expect(DB::table('maintenance_runs')->value('status'))->toBe('processing');
    DB::table('maintenance_runs')->where('id', $run->id)->update(['lease_expires_at' => now()->subMinute()]);
    $this->artisan('maintenance:sweep')->assertSuccessful();
    (new SanitizeMaintenance($run->id))->handle(new EmailHtml);
    expect(DB::table('maintenance_runs')->value('status'))->toBe('completed');
});

it('resolves cycles without recursion and durably finishes candidate batches beyond one hundred', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $a = listMessage($user, $account->id, 'A', '2026-01-01', ['message_id_header' => 'a@example.test', 'in_reply_to' => 'b@example.test']);
    $b = listMessage($user, $account->id, 'B', '2026-01-02', ['message_id_header' => 'b@example.test', 'in_reply_to' => 'a@example.test']);
    app(Threader::class)->assign($a);
    app(Threader::class)->assign($b);
    expect(DB::table('messages')->whereIn('id', [$a, $b])->distinct()->count('thread_id'))->toBe(1);
    for ($i = 0; $i < 110; $i++) {
        listMessage($user, $account->id, "Child {$i}", '2026-01-03', ['in_reply_to' => 'a@example.test']);
    }
    app(Threader::class)->assign($a);
    expect(DB::table('thread_repairs')->where('message_id', $a)->exists())->toBeTrue();
    app(Threader::class)->assign($a);
    expect(DB::table('thread_repairs')->where('message_id', $a)->exists())->toBeFalse();
    expect(DB::table('messages')->whereNotNull('thread_id')->count())->toBe(112);
});
