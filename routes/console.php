<?php

use App\Jobs\ExtractMessageAttachments;
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
