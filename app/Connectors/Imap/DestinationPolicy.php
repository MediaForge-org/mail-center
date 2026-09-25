<?php

namespace App\Connectors\Imap;

class DestinationPolicy
{
    public function approvedAddress(string $host, int $port): string
    {
        if (! in_array($port, config('mailcenter.imap.allowed_ports'), true)
            || strlen($host) > 253 || ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new ImapFailure(ImapFailure::DESTINATION, ImapFailure::safeMessage(ImapFailure::DESTINATION));
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_unique(array_merge(
                array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            )));

        if ($addresses === []) {
            throw new ImapFailure(ImapFailure::TRANSIENT, ImapFailure::safeMessage(ImapFailure::TRANSIENT));
        }

        foreach ($addresses as $address) {
            if (! $this->permitted($address)) {
                throw new ImapFailure(ImapFailure::DESTINATION, ImapFailure::safeMessage(ImapFailure::DESTINATION));
            }
        }

        return $addresses[0];
    }

    private function permitted(string $address): bool
    {
        $allowed = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if ($allowed) {
            return true;
        }

        foreach (config('mailcenter.imap.private_allowlist') as $entry) {
            [$network, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);
            $a = inet_pton($address);
            $n = inet_pton($network);
            if ($a === false || $n === false || strlen($a) !== strlen($n)) {
                continue;
            }
            $prefix = $bits === null ? strlen($a) * 8 : (int) $bits;
            if ($prefix < 0 || $prefix > strlen($a) * 8) {
                continue;
            }
            $bytes = intdiv($prefix, 8);
            $remainder = $prefix % 8;
            if (substr($a, 0, $bytes) === substr($n, 0, $bytes)
                && ($remainder === 0 || (ord($a[$bytes]) & (0xFF << (8 - $remainder))) === (ord($n[$bytes]) & (0xFF << (8 - $remainder))))) {
                return true;
            }
        }

        return false;
    }
}
