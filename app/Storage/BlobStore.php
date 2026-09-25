<?php

namespace App\Storage;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class BlobStore
{
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
