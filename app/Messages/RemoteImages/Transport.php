<?php

namespace App\Messages\RemoteImages;

use App\Messages\InlineImages;

class Transport
{
    /** Fresh cURL handle per hop: no cookies, auth, proxy environment, DNS cache or automatic redirects. */
    public function get(string $url, string $host, int $port, string $ip, float $timeout): array
    {
        $curl = curl_init();
        $body = '';
        $headers = [];
        $headerBytes = 0;
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            // A validated literal IP is already pinned and needs no DNS override.
            CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP) ? [] : [$host.':'.$port.':'.$address],
            // Ignore local netrc credentials (libcurl NETRC_IGNORED = 0).
            CURLOPT_NETRC => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_USERAGENT => 'MailCenter-Image/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/png,image/jpeg,image/gif,image/webp', 'Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) {
                    return 0;
                }
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > InlineImages::MAX_BYTES) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($curl) === false) {
                throw new \RuntimeException('Remote image unavailable.');
            }

            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
        } finally {
            curl_close($curl);
        }
    }
}
