<?php

namespace App\Messages;

final class AttachmentMetadata
{
    public static function filename(?string $value): string
    {
        $value = mb_convert_encoding($value ?? '', 'UTF-8', 'UTF-8');
        $value = preg_replace('/[\p{C}\/\\\\]+/u', '_', $value) ?? '';
        $value = trim(mb_strcut($value, 0, 240, 'UTF-8'), " .\t");

        return $value === '' ? 'attachment' : $value;
    }

    public static function contentType(?string $value): string
    {
        // Conservative download allowlist. Active and unknown formats are binary downloads.
        $value = strtolower(trim($value ?? ''));

        return in_array($value, [
            'text/plain', 'text/csv', 'application/pdf', 'application/zip',
            'application/gzip', 'application/octet-stream', 'image/png', 'image/jpeg',
            'image/gif', 'image/webp', 'audio/mpeg', 'video/mp4',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], true) ? $value : 'application/octet-stream';
    }

    public static function fromRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'filename' => self::filename($row->filename),
            'content_type' => self::contentType($row->content_type),
            'size_bytes' => (int) $row->size_bytes,
            'inline' => $row->disposition === 'inline',
            'downloadable' => true,
        ];
    }
}
