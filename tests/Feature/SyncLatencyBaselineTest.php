<?php

use App\Connectors\Imap\ImapClient;
use App\Jobs\SyncAccountJob;
use App\Models\User;
use App\Sync\SyncRunner;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImapClient;

uses(RefreshDatabase::class);

it('records the pre M311 retry and queue baseline on synthetic mail', function () {
    if (! getenv('M311_BASELINE')) {
        $this->markTestSkipped('Historical baseline; explicitly opt in before changing sync behavior.');
    }
    Queue::fake();
    $root = sys_get_temp_dir().'/mailcenter-baseline-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $root]);
    $client = new FakeImapClient;
    app()->instance(ImapClient::class, $client);
    $user = User::factory()->create();
    $account = makeAccount($user);
    $client->add(FakeImapClient::raw('Baseline retry'));
    $client->fetchFailures[1] = new RuntimeException('Synthetic temporary failure');
    $runner = app(SyncRunner::class);
    $runner->run($account->id, 'manual');
    unset($client->fetchFailures[1]);
    $runner->run($account->id, 'manual');
    expect(DB::table('messages')->count())->toBe(0);
    $retry = DB::table('sync_failures')->first();
    expect(strtotime($retry->next_attempt_at) - strtotime($retry->first_failed_at))->toBe(120);
    $this->travel(121)->seconds();
    $runner->run($account->id, 'manual');
    expect(DB::table('messages')->count())->toBe(1);
    $store = app('cache')->store('array');
    app()->instance(Repository::class, $store);
    $lock = new UniqueLock($store);
    expect($lock->acquire(new SyncAccountJob($account->id)))->toBeTrue();
    $this->actingAs($user)->postJson("/api/accounts/{$account->id}/sync")->assertAccepted();
    Queue::assertNotPushed(SyncAccountJob::class);
    $lock->release(new SyncAccountJob($account->id));
    $times = [];
    foreach (range(1, 30) as $iteration) {
        $client->add(FakeImapClient::raw('Baseline '.$iteration));
        $start = hrtime(true);
        $runner->run($account->id, 'manual');
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    fwrite(STDERR, '\nM311 BASELINE '.json_encode(['iterations' => 30, 'synthetic_direct_runner_ms' => ['p50' => $times[14], 'p95' => $times[28]], 'retry_delay_seconds' => 120, 'unique_lock_silently_drops_accepted_request' => true])."\n");
    File::deleteDirectory($root);
});

it('records the comparable current direct engine latency', function () {
    if (! getenv('M311_AFTER')) {
        $this->markTestSkipped('Explicit isolated performance measurement.');
    }
    Queue::fake();
    $root = sys_get_temp_dir().'/mailcenter-after-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $root]);
    $client = new FakeImapClient;
    app()->instance(ImapClient::class, $client);
    $account = makeAccount(User::factory()->create());
    $runner = app(SyncRunner::class);
    $client->add(FakeImapClient::raw('Warmup'));
    $runner->run($account->id, 'manual');
    $times = [];
    foreach (range(1, 30) as $i) {
        $client->add(FakeImapClient::raw('After '.$i));
        $start = hrtime(true);
        $runner->run($account->id, 'manual');
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    expect(DB::table('messages')->count())->toBe(31);
    fwrite(STDERR, '\nM311 CURRENT '.json_encode(['iterations' => 30, 'synthetic_direct_runner_ms' => ['p50' => $times[14], 'p95' => $times[28]]])."\n");
    File::deleteDirectory($root);
});
