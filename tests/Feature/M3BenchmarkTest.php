<?php

use App\Http\Controllers\Api\MessageController;
use App\Messages\MessageFilter;
use App\Messages\MessageView;
use App\Models\User;
use App\Organization\SystemFolders;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('records the ten account 100k M3 latency acceptance benchmark', function () {
    $user = User::factory()->create();
    $accounts = [];
    $folders = array_map(fn ($role) => SystemFolders::idFor($user->id, $role), ['inbox', 'sent', 'archive']);
    foreach (range(1, 10) as $index) {
        $accounts[] = makeAccount($user);
    }
    $seed = listMessage($user, $accounts[0]->id, 'Benchmark seed', '2026-01-01');
    foreach ($accounts as $index => $account) {
        $start = $index * 10000 + 1;
        $end = ($index + 1) * 10000 - ($index === 9 ? 1 : 0);
        DB::statement(<<<'SQL'
            INSERT INTO messages(user_id,mail_account_id,dedupe_key,subject,from_name,from_address,snippet,sort_date,received_at,size_bytes,raw_blob_sha256,is_read,is_done,remote_status,folder_id,created_at,updated_at)
            SELECT user_id, ?::bigint, 'benchmark-' || n, 'Synthetic transactional message ' || n, 'Example Sender', 'sender@example.test', 'A realistic short preview for benchmark serialization.',
                timestamp '2026-09-01' - n * interval '1 second', timestamp '2026-09-01' - n * interval '1 second', 1,raw_blob_sha256,n % 5 <> 0,n % 7 = 0,
                CASE WHEN n % 23 = 0 THEN 'removed' ELSE 'present' END, (ARRAY[?::bigint,?::bigint,?::bigint])[1+n%3],now(),now()
            FROM messages CROSS JOIN generate_series(?::int,?::int) n WHERE id = ?
            SQL, [$account->id, ...$folders, $start, $end, $seed]);
    }
    DB::statement('ANALYZE messages');
    DB::statement('SET statement_timeout = 5000');
    expect(DB::table('messages')->count())->toBe(100000);
    $controller = app(MessageController::class);
    $call = function (string $method, array $params = []) use ($controller, $user) {
        $request = Request::create('/api/benchmark', 'GET', $params);
        $request->setUserResolver(fn () => $user);

        return $controller->$method($request);
    };
    $cursor = json_decode($call('index')->getContent(), true)['next_cursor'];
    $cases = [
        'all_first_50' => ['index', ['view' => 'all', 'limit' => 50]],
        'inbox_first_50' => ['index', ['view' => 'inbox', 'limit' => 50]],
        'unread_first_50' => ['index', ['view' => 'unread', 'limit' => 50]],
        'account_first_50' => ['index', ['account_id' => $accounts[4]->id, 'limit' => 50]],
        'all_second_50' => ['index', ['cursor' => $cursor, 'limit' => 50]],
        'mailbox_counts' => ['counts', []],
        'freshness_poll' => ['changes', ['since' => (string) DB::table('user_change_versions')->where('user_id', $user->id)->value('version')]],
    ];
    $results = [];
    foreach ($cases as $name => [$method, $params]) {
        $samples = [];
        $first = 0;
        for ($i = -5; $i < 100; $i++) {
            $start = hrtime(true);
            $response = $call($method, $params);
            $elapsed = (hrtime(true) - $start) / 1e6;
            expect($response->getStatusCode())->toBe(200);
            if ($i === -5) {
                $first = $elapsed;
            }
            if ($i >= 0) {
                $samples[] = $elapsed;
            }
        }
        sort($samples);
        $results[$name] = ['first_touch_ms' => round($first, 3), 'p50_ms' => round($samples[49], 3), 'p95_ms' => round($samples[94], 3), 'max_ms' => round(max($samples), 3), 'iterations' => count($samples)];
    }
    $workers = [];
    for ($i = 0; $i < 5; $i++) {
        $process = proc_open([PHP_BINARY, base_path('tests/performance/poll-worker.php'), (string) $user->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $workers[] = [$process, $pipes];
    }
    $parallel = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error);
        $parallel = [...$parallel, ...json_decode($output, true, flags: JSON_THROW_ON_ERROR)];
    }
    sort($parallel);
    $results['freshness_five_clients'] = ['p50_ms' => round($parallel[249], 3), 'p95_ms' => round($parallel[474], 3), 'max_ms' => round(max($parallel), 3), 'iterations' => 500];
    expect($results['freshness_five_clients']['p95_ms'])->toBeLessThan(100);
    $plans = [];
    foreach (MessageView::cases() as $view) {
        $query = (new MessageFilter($view))->query($user->id)->select('messages.id')->orderByDesc('messages.sort_date')->orderByDesc('messages.id')->limit(51);
        $plans[$view->value] = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query->toSql(), $query->getBindings());
    }
    file_put_contents(storage_path('logs/m3-benchmark.json'), json_encode([
        'environment' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'postgres' => DB::selectOne('SELECT version() AS version')->version, 'cpu' => trim((string) shell_exec('lscpu 2>/dev/null || cat /proc/cpuinfo | head -25')), 'memory' => trim((string) shell_exec('head -3 /proc/meminfo'))],
        'methodology' => 'In-process Laravel controller through JSON serialization, PostgreSQL over Docker network. Not HTTP/browser latency. 5 warmups, 100 timed samples per case; caches not forcibly dropped; first-touch is post-seeding, NOT cold disk.',
        'accounts' => 10, 'messages' => 100000, 'results' => $results, 'plans' => $plans,
    ], JSON_PRETTY_PRINT));
    DB::statement('SET statement_timeout = 0');
    foreach ($results as $name => $result) {
        if (str_contains($name, '_50')) {
            expect($result['p95_ms'])->toBeLessThan(250);
        }
        if ($name === 'freshness_poll') {
            expect($result['p95_ms'])->toBeLessThan(100);
        }
    }
})->group('performance');
