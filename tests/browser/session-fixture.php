<?php

use App\Ingestion\MessageIngestor;
use App\Models\MailAccount;
use App\Models\User;
use App\Storage\BlobStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Reuse the strict pre-boot database guard. This script cannot target development mail.
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
config(['mailcenter.blobs.root' => '/tmp/mailcenter-browser-blobs']);
$users = [];
foreach (['owner', 'foreign'] as $name) {
    $users[] = User::updateOrCreate(['email' => $name.'@browser.test'], ['name' => ucfirst($name), 'password' => Hash::make('browser-test-password')]);
}
$account = MailAccount::firstOrCreate(['user_id' => $users[0]->id, 'email_address' => 'work@browser.test'], [
    'provider' => 'imap', 'display_name' => 'Work', 'short_label' => 'WK', 'incoming' => [],
    'sync_enabled' => false, 'sync_status' => 'idle', 'last_successful_sync_at' => now(),
]);
$folder = $account->remoteFolders()->firstOrCreate(['raw_name' => 'INBOX'], ['name' => 'INBOX', 'role' => 'inbox']);
app(BlobStore::class)->put(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png'));
app(BlobStore::class)->put('Actual attachment bytes');
$png = base64_encode(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png'));
$raw = "From: Sender <sender@browser.test>\r\nTo: Recipient <work@browser.test>\r\nSubject: A compact message with an inline image\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=parts\r\n\r\n"
    ."--parts\r\nContent-Type: text/html\r\n\r\n<p>Browser session content</p><img src=\"cid:photo\"><img src=\"https://attacker.invalid/pixel\">\r\n"
    ."--parts\r\nContent-Type: image/png\r\nContent-Disposition: inline\r\nContent-ID: <photo>\r\nContent-Transfer-Encoding: base64\r\n\r\n$png\r\n"
    ."--parts\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=proof.txt\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('Actual attachment bytes')."\r\n--parts--\r\n";
$id = app(MessageIngestor::class)->ingest($account, $folder, 1, 100, $raw, [], null);
$other = app(MessageIngestor::class)->ingest($account, $folder, 2, 100, str_replace('Subject: A compact message', 'Subject: Another message', $raw), [], null);
$rows = DB::table('attachments')->where('message_id', $id)->get();
echo json_encode(['message' => $id, 'other' => $other, 'account' => $account->id, 'inline' => $rows->firstWhere('disposition', 'inline')->id, 'attachment' => $rows->firstWhere('disposition', 'attachment')->id], JSON_THROW_ON_ERROR);
