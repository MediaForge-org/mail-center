<?php

use App\Connectors\Imap\ImapClient;
use App\Jobs\SyncAccountJob;
use App\Models\User;
use App\Sync\SyncRequests;
use App\Sync\SyncRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImapClient;

uses(DatabaseMigrations::class);

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('dispatches only after the durable request transaction commits and never on rollback', function () {
    Queue::fake();
    $account = makeAccount(User::factory()->create());
    DB::beginTransaction();
    app(SyncRequests::class)->request($account->id, 'manual');
    Queue::assertNothingPushed();
    DB::rollBack();
    Queue::assertNothingPushed();
    expect($account->refresh()->sync_requested_generation)->toBe(0);
    DB::beginTransaction();
    app(SyncRequests::class)->request($account->id, 'manual');
    Queue::assertNothingPushed();
    DB::commit();
    Queue::assertPushed(SyncAccountJob::class, 1);
    expect($account->refresh()->sync_requested_generation)->toBe(1);
});

it('publishes committed mail and its version while later remote work is still running', function () {
    Queue::fake();
    $root = sys_get_temp_dir().'/mailcenter-commit-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $root]);
    $client = new FakeImapClient;
    app()->instance(ImapClient::class, $client);
    $user = User::factory()->create();
    $account = makeAccount($user);
    app(SyncRunner::class)->run($account->id);
    $version = DB::table('user_change_versions')->where('user_id', $user->id)->value('version');
    $client->add(FakeImapClient::raw('Committed first'));
    $client->add(FakeImapClient::raw('Later remote work'));
    $db = config('database.connections.pgsql');
    $observer = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}", $db['username'], $db['password']);
    $observed = false;
    $client->onFetch = function ($uid) use ($observer, $version, &$observed) {
        if ($uid !== 2) {
            return;
        }
        expect((int) $observer->query("SELECT count(*) FROM messages WHERE subject = 'Committed first'")->fetchColumn())->toBe(1);
        expect((int) $observer->query('SELECT max(version) FROM user_change_versions')->fetchColumn())->toBeGreaterThan((int) $version);
        expect((int) $observer->query("SELECT count(*) FROM sync_runs WHERE status = 'running'")->fetchColumn())->toBe(1);
        $observed = true;
    };
    app(SyncRequests::class)->request($account->id, 'manual');
    (new SyncAccountJob($account->id))->handle(app(SyncRunner::class));
    expect($observed)->toBeTrue();
    File::deleteDirectory($root);
});
