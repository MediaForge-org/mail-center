<?php

use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use App\Sync\SyncRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\Support\FakeImapClient;

putenv('REDIS_PREFIX=mailcenter-m311-isolated-');
$_ENV['REDIS_PREFIX'] = $_SERVER['REDIS_PREFIX'] = 'mailcenter-m311-isolated-';
putenv('HORIZON_PREFIX=mailcenter-m311-horizon:');
$_ENV['HORIZON_PREFIX'] = $_SERVER['HORIZON_PREFIX'] = 'mailcenter-m311-horizon:';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/sync-bindings.php';
Vite::useHotFile('/tmp/mailcenter-browser-no-hot');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/build/') && is_file(dirname(__DIR__, 2).'/public'.$path)) {
    return false;
}

Route::middleware(['web', 'auth'])->get('/__fixture/setup', function (Request $request) {
    $account = MailAccount::where('user_id', $request->user()->id)->where('email_address', 'latency@example.test')->firstOrFail();
    $case = $request->query('case', 'B');
    if ($case === 'status') {
        return response()->json([
            'account' => $account,
            'connect_started' => DB::table('fixture_m311')->where('account_id', $account->id)->value('connect_started'),
            'runs' => DB::table('sync_runs')->where('mail_account_id', $account->id)->orderByDesc('id')->limit(4)->get(),
        ]);
    }
    if ($case === 'scheduled') {
        DB::table('fixture_m311')->where('account_id', $account->id)->update(['connect_ms' => 1000, 'connect_started' => null]);
        app(SyncRequests::class)->request($account->id);

        return response()->json(['scheduled' => true]);
    }
    $remote = new FakeImapClient;
    $remote->mailboxes = json_decode(DB::table('fixture_m311')->where('account_id', $account->id)->value('mailboxes'), true);
    $folder = in_array($case, ['C', 'D', 'idle-sent'], true) ? 'Sent' : 'INBOX';
    $slowBefore = 0;
    if ($case === 'D') {
        foreach (range(1, 20) as $i) {
            $remote->add(FakeImapClient::raw('History '.microtime(true).'-'.$i), [], 'Sent');
        }
        $slowBefore = $remote->mailboxes['Sent']['uidnext'] - 1;
        $account->remoteFolders()->where('role', 'sent')->update(['sync_high_uid' => $slowBefore, 'backfill_low_uid' => $slowBefore + 1, 'backfill_completed_at' => null]);
    }
    $subject = 'Latency '.bin2hex(random_bytes(8));
    if ($case !== 'A') {
        $raw = FakeImapClient::raw($subject);
        if ($folder === 'Sent') {
            // A sent reply to an existing received message; it must join that conversation.
            $raw = str_replace('From: Sender <sender@example.test>', 'From: latency@example.test', $raw);
            $raw = str_replace("Subject: {$subject}\r\n", "Subject: Re: {$subject}\r\nIn-Reply-To: <".md5('Initial 2Hello')."@example.test>\r\nReferences: <".md5('Initial 2Hello')."@example.test>\r\n", $raw);
            $subject = 'Re: '.$subject;
        }
        $remote->add($raw, [], $folder);
    }
    DB::table('fixture_m311')->where('account_id', $account->id)->update(['mailboxes' => json_encode($remote->mailboxes), 'connect_ms' => max(0, min(2000, (int) $request->query('connect_ms', 0))), 'slow_before' => $slowBefore]);
    if ($case === 'F') {
        // A real Redis delivery on a parked normal queue models an occupied normal supervisor.
        $originalQueue = app('queue');
        Queue::fake();
        app(SyncRequests::class)->request($account->id);
        Queue::swap($originalQueue);
        app('queue')->connection('mail_sync')->push((new SyncAccountJob($account->id))->onQueue('sync-parked'));
    }
    $eventAt = microtime(true);
    if (str_starts_with($case, 'idle-')) {
        $id = $account->remoteFolders()->where('raw_name', $folder)->value('id');
        $socket = stream_socket_client('tcp://127.0.0.1:11430', $errno, $error, 2);
        fwrite($socket, 'notify:'.$folder."\n");
        fclose($socket);
    }

    return response()->json(['account' => $account->id, 'subject' => $subject, 'at' => $eventAt]);
});
header('X-M311-Request-Start: '.($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
header('X-M311-Boot-End: '.microtime(true));
$app->handleRequest(Request::capture());
