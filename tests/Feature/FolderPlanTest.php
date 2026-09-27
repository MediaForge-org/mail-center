<?php

use App\Messages\MessageFilter;
use App\Models\User;
use App\Organization\OrganizationService;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('serves a folder feed and grouped folder counts without a per-folder query on 100 folders and 100k messages', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $seed = listMessage($user, $account->id, 'Seed', '2026-01-01');
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    $organization = app(OrganizationService::class);
    $folderIds = [$inbox];
    for ($i = 0; $i < 96; $i++) {
        $folderIds[] = $organization->createFolder($user->id, "Bulk {$i}")['id'];
    }
    expect(DB::table('folders')->where('user_id', $user->id)->count())->toBe(3 + 5 + 96); // system + seeded defaults + bulk

    $target = $folderIds[array_rand($folderIds)];
    DB::statement(<<<'SQL'
        INSERT INTO messages (user_id, mail_account_id, dedupe_key, subject, sort_date, size_bytes, raw_blob_sha256, is_read, folder_id, created_at, updated_at)
        SELECT user_id, mail_account_id, 'folder-plan-' || n, 'Synthetic', now() - n * interval '1 second', 1, raw_blob_sha256, n % 3 = 0, ?::bigint, now(), now()
        FROM messages CROSS JOIN generate_series(1, 100000) n WHERE id = ?
        SQL, [$target, $seed]);
    DB::statement('ANALYZE messages');

    $feedQuery = (new MessageFilter(folderId: $target))->query($user->id)
        ->select('messages.id')->orderByDesc('messages.sort_date')->orderByDesc('messages.id')->limit(51);
    $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$feedQuery->toSql(), $feedQuery->getBindings());
    $planJson = json_encode($plan, JSON_PRETTY_PRINT);
    file_put_contents(storage_path('logs/mailcenter-m41-folder-plan.json'), $planJson);
    expect($planJson)->toContain('messages_folder_feed_idx');

    DB::enableQueryLog();
    $response = $this->actingAs($user)->getJson('/api/mailbox-counts')->assertOk();
    $folderCountQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'group by "messages"."folder_id"'))->count();
    DB::disableQueryLog();
    expect($folderCountQueries)->toBe(1); // one grouped aggregate, never one query per folder
    expect($response->json('folders.'.$target.'.total'))->toBe(100000);

    $page = $this->getJson("/api/messages?folder_id={$target}")->assertOk();
    expect($page->json('data'))->toHaveCount(50);
});
