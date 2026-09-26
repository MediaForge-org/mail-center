<?php

use App\Jobs\ExtractMessageAttachments;
use App\Jobs\PushRemoteFlagChangesJob;
use App\Jobs\RepairThread;
use App\Jobs\SanitizeMaintenance;
use App\Messages\EmailHtml;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sync:dispatch-due')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Explicit operator action after deploying a sanitizer version; never invoked by a read request.
Artisan::command('messages:sanitize-html', function () {
    DB::table('maintenance_runs')->insertOrIgnore([
        'kind' => 'sanitize-html', 'target_version' => EmailHtml::VERSION,
        'created_at' => now(), 'updated_at' => now(), 'next_attempt_at' => now(),
    ]);
    $this->call('maintenance:sweep');
    $this->info('Recorded durable HTML sanitization run. Inspect maintenance_runs for progress/errors.');
})->purpose('Queue versioned HTML sanitization from retained raw blobs');

Artisan::command('messages:extract-attachments', function () {
    DB::table('messages')->whereNull('attachments_extracted_at')
        ->select('id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                ExtractMessageAttachments::dispatch($row->id);
            }
        });
    $this->info('Queued pending attachment extraction.');
})->purpose('Extract attachment metadata and blobs from verified retained messages');

Artisan::command('writeback:sweep', function () {
    DB::table('remote_flag_changes')->join('mail_accounts', 'mail_accounts.id', '=', 'remote_flag_changes.mail_account_id')
        ->where('mail_accounts.enabled', true)->where('mail_accounts.write_back_seen', true)->whereNull('mail_accounts.deleted_at')
        ->where('mail_accounts.sync_status', '<>', 'auth_failed')
        ->where(function ($q) {
            $q->where(fn ($q) => $q->where('status', 'pending')->where('remote_flag_changes.created_at', '<=', now()->subMinutes(2))->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_expires_at', '<=', now()));
        })->select('mail_account_id')->distinct()->orderBy('mail_account_id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                PushRemoteFlagChangesJob::dispatch($row->mail_account_id);
            }
        });
})->purpose('Recover due Seen intents and expired processing leases');
Schedule::command('writeback:sweep')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();

Artisan::command('messages:assign-threads', function () {
    DB::statement('INSERT INTO thread_repairs(message_id, after_id, updated_at) SELECT id, 0, now() FROM messages WHERE thread_id IS NULL ON CONFLICT(message_id) DO NOTHING');
    $this->call('threads:sweep');
    $this->info('Queued unassigned messages for header-only conversation assignment.');
})->purpose('Explicitly backfill conversations without reading raw MIME');

Artisan::command('threads:sweep', function () {
    DB::table('thread_repairs')->orderBy('message_id')->chunkById(200, function ($rows) {
        foreach ($rows as $row) {
            RepairThread::dispatch($row->message_id);
        }
    }, 'message_id');
})->purpose('Redeliver bounded durable thread repairs');
Schedule::command('threads:sweep')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();

Artisan::command('maintenance:sweep', function () {
    DB::table('maintenance_runs')->where('kind', 'sanitize-html')->whereIn('status', ['pending', 'processing'])
        ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
        ->orderBy('id')->each(function ($run) {
            SanitizeMaintenance::dispatch($run->id);
        });
})->purpose('Recover operator-created maintenance runs and expired leases');
Schedule::command('maintenance:sweep')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();
