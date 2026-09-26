<?php

namespace App\Jobs;

use App\Messages\EmailHtml;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use ZBateson\MailMimeParser\Message;

class SanitizeMessageHtml implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(EmailHtml $sanitizer): void
    {
        $row = DB::table('messages')->where('id', $this->messageId)->first(['raw_blob_sha256', 'parser_version']);
        if ($row === null) {
            return;
        }
        $sha = $row->raw_blob_sha256;
        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return;
        }
        $root = rtrim((string) (config('mailcenter.blobs.root') ?: storage_path('app/private')), '/');
        $path = $root.'/blobs/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
        if (! is_file($path) || filesize($path) > config('mailcenter.imap.max_message_bytes')) {
            return;
        }
        $raw = file_get_contents($path);
        if ($raw === false || hash('sha256', $raw) !== $sha) {
            return;
        }
        try {
            $result = $sanitizer->sanitizeMessage(Message::from($raw, true));
        } catch (\Throwable) {
            // Keep old output stale; do not leak MIME/parser errors into queue logs.
            return;
        }
        DB::table('message_bodies')->where('message_id', $this->messageId)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('messages')->whereColumn('messages.id', 'message_bodies.message_id')->where('raw_blob_sha256', $sha)->where('parser_version', $row->parser_version))
            ->where('sanitizer_version', '<>', EmailHtml::VERSION)
            ->update([...$result, 'updated_at' => now()]);
    }
}
