<?php

namespace App\Messages\RemoteImages;

use Symfony\Component\HttpFoundation\IpUtils;

final class Destination
{
    /** Strict ASCII URL grammar avoids parser disagreements and alternate numeric host syntax. */
    public function parse(string $url): array
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url)) {
            throw new \RuntimeException('Remote image unavailable.');
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Remote image unavailable.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true) || strlen($host) > 253
            || (! filter_var($host, FILTER_VALIDATE_IP) && (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($host, '.') || ctype_digit(str_replace('.', '', $host))))) {
            throw new \RuntimeException('Remote image unavailable.');
        }

        return ['host' => $host, 'port' => $port];
    }

    public function validateAddresses(array $addresses): string
    {
        if ($addresses === []) {
            throw new \RuntimeException('Remote image unavailable.');
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || IpUtils::checkIp($ip, ['0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'])
                || (str_contains($ip, ':') && (! IpUtils::checkIp($ip, '2000::/3') || IpUtils::checkIp($ip, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'])))) {
                throw new \RuntimeException('Remote image unavailable.');
            }
        }

        return $addresses[0];
    }
}
