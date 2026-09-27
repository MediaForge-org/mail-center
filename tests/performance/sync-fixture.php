<?php

use App\Accounts\Credentials\CredentialVault;
use App\Models\MailAccount;
use App\Models\User;
use App\Sync\SyncRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImapClient;

putenv('REDIS_PREFIX=mailcenter-m311-isolated-');
$_ENV['REDIS_PREFIX'] = $_SERVER['REDIS_PREFIX'] = 'mailcenter-m311-isolated-';
putenv('HORIZON_PREFIX=mailcenter-m311-horizon:');
$_ENV['HORIZON_PREFIX'] = $_SERVER['HORIZON_PREFIX'] = 'mailcenter-m311-horizon:';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/sync-bindings.php';
// Optional explicit reset is protected by the early postgres-test/mailcenter_test guard.
Artisan::call(in_array('--reset-test-fixture', $argv ?? [], true) ? 'migrate:fresh' : 'migrate', ['--force' => true]);
Queue::fake();

DB::statement('CREATE TABLE IF NOT EXISTS fixture_m311(account_id bigint PRIMARY KEY, mailboxes jsonb NOT NULL, connect_ms int NOT NULL DEFAULT 0, connect_started double precision, slow_before int NOT NULL DEFAULT 0)');
$user = User::firstOrCreate(['email' => 'latency@browser.test'], ['name' => 'Latency fixture', 'password' => Hash::make('browser-test-password')]);
$account = MailAccount::firstOrCreate(['user_id' => $user->id, 'email_address' => 'latency@example.test'], [
    'provider' => 'imap', 'display_name' => 'Latency fixture', 'short_label' => 'LT',
    'incoming' => ['host' => 'imap.example.test', 'port' => 993, 'security' => 'tls', 'username' => 'synthetic'],
    'sync_enabled' => true, 'sync_status' => 'idle', 'next_sync_at' => now()->addHour(),
]);
$account->credentials()->firstOrCreate(['purpose' => 'incoming'], ['kind' => 'password', ...app(CredentialVault::class)->encrypt('synthetic-only')]);
$remote = new FakeImapClient;
$remote->mailbox('Sent', 'sent');
// Canonical acceptance mailbox: 25 received Inbox messages and 2 messages sent by the account.
foreach (range(1, 25) as $i) {
    $remote->add(FakeImapClient::raw('Initial '.$i));
}
foreach (range(1, 2) as $i) {
    $remote->add(str_replace('From: Sender <sender@example.test>', 'From: latency@example.test', FakeImapClient::raw('Sent initial '.$i)), ['\\Seen'], 'Sent');
}
DB::table('fixture_m311')->updateOrInsert(['account_id' => $account->id], ['mailboxes' => json_encode($remote->mailboxes)]);
app(SyncRunner::class)->run($account->id);
app(SyncRunner::class)->run($account->id);
echo json_encode(['account' => $account->id, 'user' => $user->id])."\n";
