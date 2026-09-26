<?php

namespace App\Messages\RemoteImages;

use App\Messages\InlineImages;

final class Raster
{
    /** Decode and re-encode to discard metadata/trailing payloads; GIF animations become a still image. */
    public function validate(string $bytes, string $declared): array
    {
        $mime = strtolower(trim(explode(';', $declared)[0]));
        $size = @getimagesizefromstring($bytes);
        if (strlen($bytes) > InlineImages::MAX_BYTES || $size === false || ! in_array($mime, InlineImages::TYPES, true)
            || $size['mime'] !== $mime || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 8192 || $size[1] > 8192 || $size[0] * $size[1] > 16_000_000) {
            throw new \RuntimeException('Remote image unavailable.');
        }
        // Obvious active payloads are refused even if a permissive decoder accepts the container.
        if (preg_match('/<(?:html|script|svg|\?xml)\b/i', $bytes) || str_contains($bytes, '<?php')) {
            throw new \RuntimeException('Remote image unavailable.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \RuntimeException('Remote image unavailable.');
        }
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            imagesavealpha($image, true);
            if (! imagepng($image, $stream) || ftell($stream) > InlineImages::MAX_BYTES) {
                throw new \RuntimeException('Remote image unavailable.');
            }
            rewind($stream);

            return ['mime' => 'image/png', 'bytes' => stream_get_contents($stream)];
        } finally {
            fclose($stream);
            imagedestroy($image);
        }
    }
}
