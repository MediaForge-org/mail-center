<?php

namespace App\Ingestion;

use App\Messages\AttachmentMetadata;
use App\Storage\BlobStore;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\IMessagePart;
use ZBateson\MailMimeParser\Message\IMultiPart;
use ZBateson\MailMimeParser\Message\PartFilter;

class AttachmentExtractor
{
    public function __construct(private readonly BlobStore $blobs) {}

    public function extract(int $messageId, string $raw): void
    {
        $limit = (int) config('mailcenter.imap.max_message_bytes');
        if (strlen($raw) > $limit) {
            throw new RuntimeException('Message exceeds extraction limit.');
        }
        DB::transaction(function () use ($messageId, $raw, $limit) {
            $row = DB::table('messages')->where('id', $messageId)->lockForUpdate()->first();
            if ($row === null || $row->attachments_extracted_at !== null) {
                return;
            }
            $message = Message::from($raw, true);
            $count = 0;
            $order = 0;
            $total = 0;
            $hasAttachments = false;
            $filter = PartFilter::fromAttachmentFilter();
            $walk = function (IMessagePart $part, string $path, int $depth) use (&$walk, &$count, &$order, &$total, &$hasAttachments, $filter, $messageId, $limit): void {
                if (++$count > 500 || $depth > 20) {
                    throw new RuntimeException('MIME structure exceeds extraction limit.');
                }
                if ($filter($part) || ($part->getFilename() !== null && (! $part instanceof IMultiPart || $part->getChildCount() === 0))) {
                    $stream = $part->getBinaryContentStream();
                    $blob = $stream === null ? ['sha256' => $this->blobs->put(''), 'size_bytes' => 0] : $this->blobs->putStream($stream, $limit - $total);
                    $total += $blob['size_bytes'];
                    $disposition = strtolower((string) $part->getContentDisposition(null));
                    $inline = $disposition === 'inline' || ($disposition !== 'attachment' && $part->getContentId() !== null);
                    $hasAttachments = $hasAttachments || ! $inline;
                    DB::table('attachments')->insert([
                        'message_id' => $messageId, 'blob_sha256' => $blob['sha256'],
                        'filename' => AttachmentMetadata::filename($part->getFilename()),
                        'content_type' => AttachmentMetadata::contentType($part->getContentType()),
                        'size_bytes' => $blob['size_bytes'], 'disposition' => $inline ? 'inline' : 'attachment',
                        'content_id' => $part->getContentId() === null ? null : mb_substr($part->getContentId(), 0, 998),
                        'mime_part_path' => $path, 'part_order' => $order++, 'created_at' => now(),
                    ]);

                    // An attached message is one file; do not duplicate its nested attachments.
                    return;
                }
                if ($part instanceof IMultiPart) {
                    foreach ($part->getChildParts() as $index => $child) {
                        $walk($child, $path.'.'.($index + 1), $depth + 1);
                    }
                }
            };
            $walk($message, '1', 0);
            DB::table('messages')->where('id', $messageId)->update([
                'attachments_extracted_at' => now(), 'has_attachments' => $hasAttachments,
            ]);
        });
    }
}
