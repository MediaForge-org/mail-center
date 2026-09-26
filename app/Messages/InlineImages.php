<?php

namespace App\Messages;

use App\Storage\BlobStore;
use Dom\HTMLDocument;

final class InlineImages
{
    public const ATTRIBUTE = 'data-mc-resource';

    public const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly BlobStore $blobs) {}

    public function hasReference(string $html, string $identity): bool
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->getElementsByTagName('img') as $image) {
            if ($image->getAttribute(self::ATTRIBUTE) === $identity) {
                return true;
            }
        }

        return false;
    }

    /** Case-sensitive opaque identity, never a URL/path. Decode URI escapes exactly once. */
    public static function identity(?string $value, bool $uri = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value, " \t");
        if ($uri) {
            if (strncasecmp($value, 'cid:', 4) !== 0) {
                return null;
            }
            $value = rawurldecode(substr($value, 4));
        }
        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = substr($value, 1, -1);
        }
        // Conservative token subset: no controls, whitespace, URL delimiters or paths.
        if (strlen($value) > 998 || ! preg_match('/\A[A-Za-z0-9!$&\x27*+\-=^_`{|}~.@]+\z/D', $value)) {
            return null;
        }

        return hash('sha256', $value);
    }

    /** Count all matching parts before eligibility checks so duplicates always fail closed. */
    public function uniqueParts(iterable $attachments): array
    {
        $matches = [];
        foreach ($attachments as $row) {
            $key = self::identity($row->content_id);
            if ($key !== null) {
                $matches[$key] = array_key_exists($key, $matches) ? null : $row;
            }
        }

        return $matches;
    }

    /** Validate the immutable, hash-verified blob; no MIME parsing or external IO. */
    public function verifiedPath(object $row): ?string
    {
        if ($row->disposition !== 'inline' || self::identity($row->content_id) === null
            || ! in_array($row->content_type, self::TYPES, true)
            || $row->size_bytes <= 0 || $row->size_bytes > self::MAX_BYTES) {
            return null;
        }
        $path = $this->blobs->verifiedPath($row->blob_sha256);
        if ($path === null || filesize($path) !== (int) $row->size_bytes) {
            return null;
        }
        $image = @getimagesize($path);
        if ($image === false || $image['mime'] !== $row->content_type
            || $image[0] < 1 || $image[1] < 1 || $image[0] > 8192 || $image[1] > 8192
            || $image[0] * $image[1] > 16_000_000
            || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $row->content_type) {
            return null;
        }

        return $path;
    }

    /** Resolve only stored sanitizer markers after message authorization, never sender URLs. */
    public function resolve(string $html, int $messageId, iterable $attachments): string
    {
        $parts = $this->uniqueParts($attachments);
        $validated = [];
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->getElementsByTagName('img') as $image) {
            $key = (string) $image->getAttribute(self::ATTRIBUTE);
            $image->removeAttribute(self::ATTRIBUTE);
            $image->removeAttribute('src');
            $row = $parts[$key] ?? null;
            if ($row === null || (int) $row->message_id !== $messageId) {
                continue;
            }
            $validated[$key] ??= $this->verifiedPath($row) !== null;
            if ($validated[$key]) {
                $image->setAttribute('src', '/api/messages/'.$messageId.'/inline/'.(int) $row->id);
            }
        }

        return $document->body->innerHTML;
    }
}
