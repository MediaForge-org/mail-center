<?php

use App\Messages\MessageFilter;
use App\Messages\MessageView;
use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('records mailbox query plans on a synthetic large mailbox', function () {
    $user = User::factory()->create();
    $a = makeAccount($user);
    $b = makeAccount($user);
    $id = listMessage($user, $a->id, 'Seed', '2026-01-01');
    $inbox = SystemFolders::idFor($user->id, 'inbox');
    DB::statement("INSERT INTO messages (user_id,mail_account_id,dedupe_key,subject,sort_date,size_bytes,raw_blob_sha256,is_read,folder_id,created_at,updated_at)
        SELECT user_id, CASE WHEN n > 95000 THEN ?::bigint ELSE ?::bigint END, 'plan-' || n, 'Synthetic', now() - n * interval '1 second', 1,raw_blob_sha256,n % 3 = 0,?::bigint,now(),now()
        FROM messages CROSS JOIN generate_series(1,100000) n WHERE id = ?", [$a->id, $b->id, $inbox, $id]);
    DB::statement('ANALYZE messages');
    $queries = [
        'account_first_page' => (new MessageFilter(MessageView::All, $a->id))->query($user->id)->select('messages.id')->orderByDesc('sort_date')->orderByDesc('messages.id')->limit(51),
        'all_count' => (new MessageFilter)->query($user->id)->selectRaw('count(*), count(*) FILTER (WHERE messages.is_read = false)'),
        'inbox_count' => (new MessageFilter(MessageView::Inbox))->query($user->id)->selectRaw('count(*), count(*) FILTER (WHERE messages.is_read = false)'),
        'unread_count' => (new MessageFilter(MessageView::Unread))->query($user->id)->selectRaw('count(*)'),
        'account_counts' => MessageFilter::base($user->id, true)->selectRaw('messages.mail_account_id,count(*),count(*) FILTER (WHERE messages.is_read = false)')->groupBy('messages.mail_account_id'),
    ];
    $plans = [];
    foreach ($queries as $name => $query) {
        $plans[$name] = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
    }
    file_put_contents(storage_path('logs/mailcenter-m38-plans.json'), json_encode($plans, JSON_PRETTY_PRINT));
    expect(DB::table('messages')->count())->toBe(100001);
});
