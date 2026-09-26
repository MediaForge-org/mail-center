<?php

namespace App\Storage;

use Illuminate\Support\Facades\DB;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class BlobStore
{
    public function verifiedPath(string $sha): ?string
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return null;
        }
        $root = rtrim((string) (config('mailcenter.blobs.root') ?: storage_path('app/private')), '/');
        $path = $root.'/blobs/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;

        return is_file($path) && is_readable($path) && hash_file('sha256', $path) === $sha ? $path : null;
    }

    /** Publish decoded bytes incrementally, without converting text attachment charsets. */
    public function putStream(StreamInterface $stream, int $limit): array
    {
        $root = rtrim((string) (config('mailcenter.blobs.root') ?: storage_path('app/private')), '/');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create blob directory.');
        }
        $temp = tempnam($root, '.attachment-');
        if ($temp === false) {
            throw new RuntimeException('Unable to create attachment blob.');
        }
        $file = fopen($temp, 'wb');
        if ($file === false) {
            unlink($temp);
            throw new RuntimeException('Unable to write attachment blob.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! $stream->eof()) {
                $bytes = $stream->read(65536);
                $size += strlen($bytes);
                if ($size > $limit || fwrite($file, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Attachment size or storage limit exceeded.');
                }
                hash_update($hash, $bytes);
            }
            fclose($file);
            $file = null;
            $sha = hash_final($hash);
            $path = 'blobs/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
            $directory = dirname($root.'/'.$path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create blob directory.');
            }
            chmod($temp, 0600);
            if (! is_file($root.'/'.$path) && ! rename($temp, $root.'/'.$path)) {
                throw new RuntimeException('Unable to publish attachment blob.');
            }
            if ($this->verifiedPath($sha) === null) {
                throw new RuntimeException('Attachment blob integrity failure.');
            }
            DB::table('blobs')->insertOrIgnore([
                'sha256' => $sha, 'size_bytes' => $size, 'disk' => 'local',
                'path' => $path, 'encoding' => 'identity', 'created_at' => now(),
            ]);

            return ['sha256' => $sha, 'size_bytes' => $size];
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function put(string $bytes): string
    {
        $sha = hash('sha256', $bytes);
        $path = 'blobs/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
        $root = rtrim((string) (config('mailcenter.blobs.root') ?: storage_path('app/private')), '/');
        $absolute = $root.'/'.$path;
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create mail blob directory.');
        }
        if (! is_file($absolute)) {
            $temp = tempnam($directory, '.incoming-');
            if ($temp === false) {
                throw new RuntimeException('Unable to create temporary mail blob.');
            }
            try {
                if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new RuntimeException('Unable to write mail blob.');
                }
                chmod($temp, 0600);
                if (! rename($temp, $absolute)) {
                    throw new RuntimeException('Unable to publish mail blob.');
                }
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }
        if (filesize($absolute) !== strlen($bytes) || hash_file('sha256', $absolute) !== $sha) {
            throw new RuntimeException('Stored mail blob failed integrity check.');
        }
        DB::table('blobs')->insertOrIgnore([
            'sha256' => $sha, 'size_bytes' => strlen($bytes), 'disk' => 'local',
            'path' => $path, 'encoding' => 'identity', 'created_at' => now(),
        ]);

        return $sha;
    }
}
