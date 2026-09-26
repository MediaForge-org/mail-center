<?php

use App\Jobs\ExtractMessageAttachments;
use App\Jobs\PushRemoteFlagChangesJob;
use App\Jobs\SanitizeMessageHtml;
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
    DB::table('message_bodies')
        ->where('sanitizer_version', '<>', EmailHtml::VERSION)
        ->orderBy('message_id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                SanitizeMessageHtml::dispatch($row->message_id);
            }
        }, 'message_id');
    $this->info('Queued stale message bodies for HTML sanitization.');
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
