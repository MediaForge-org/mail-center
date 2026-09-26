<?php

namespace App\Jobs;

use App\Ingestion\AttachmentExtractor;
use App\Storage\BlobStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExtractMessageAttachments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(BlobStore $blobs, AttachmentExtractor $extractor): void
    {
        $row = DB::table('messages')->where('id', $this->messageId)
            ->whereNull('attachments_extracted_at')->first(['raw_blob_sha256']);
        if ($row === null) {
            return;
        }
        $path = $blobs->verifiedPath($row->raw_blob_sha256);
        if ($path === null || filesize($path) > config('mailcenter.imap.max_message_bytes')) {
            return;
        }
        try {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                $extractor->extract($this->messageId, $raw);
            }
        } catch (\Throwable) {
            // Unavailable/invalid MIME remains retryable without disclosing parser input.
        }
    }
}
