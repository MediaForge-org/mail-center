<?php

// Includes the pre-boot postgres-test/mailcenter_test guard and the resource fixtures.
require __DIR__.'/session-fixture.php';

use App\Conversations\Threader;
use App\Ingestion\MessageIngestor;
use App\Models\MailAccount;
use Illuminate\Support\Facades\DB;

DB::table('messages')->where('id', $id)->update(['message_id_header' => 'browser-parent@example.test']);
DB::table('messages')->where('id', $other)->update(['message_id_header' => 'browser-child@example.test', 'in_reply_to' => 'browser-parent@example.test']);
app(Threader::class)->assign($id);
app(Threader::class)->assign($other);
$sent = $account->remoteFolders()->firstOrCreate(['raw_name' => 'Sent'], ['name' => 'Sent', 'role' => 'sent']);
$archive = $account->remoteFolders()->firstOrCreate(['raw_name' => 'Archive'], ['name' => 'Archive', 'role' => 'other']);
foreach (range(1, 65) as $number) {
    $raw = "From: Example <example@browser.test>\r\nTo: work@browser.test\r\nMessage-ID: <acceptance-{$number}@example.test>\r\nSubject: Acceptance plain {$number}\r\nContent-Type: text/plain\r\n\r\nPlain text acceptance body {$number}. <script>This stays text</script>";
    $target = [$folder, $sent, $archive][$number % 3];
    app(MessageIngestor::class)->ingest($account, $target, 100 + $number, 100, $raw, [], '2020-01-01T00:00:00Z');
}
$disabled = MailAccount::firstOrCreate(['user_id' => $users[0]->id, 'email_address' => 'disabled@browser.test'], [
    'provider' => 'imap', 'display_name' => 'Disabled archive', 'short_label' => 'DA', 'incoming' => [], 'enabled' => false, 'sync_enabled' => false,
]);
$disabledFolder = $disabled->remoteFolders()->firstOrCreate(['raw_name' => 'INBOX'], ['name' => 'INBOX', 'role' => 'inbox']);
app(MessageIngestor::class)->ingest($disabled, $disabledFolder, 1, 100, "From: example@browser.test\r\nSubject: Disabled retained mail\r\n\r\nRetained mail", [], '2020-01-01T00:00:00Z');
