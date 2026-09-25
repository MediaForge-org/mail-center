<?php

use App\Connectors\Imap\DestinationPolicy;
use App\Connectors\Imap\ImapFailure;

it('accepts a public address on an IMAP port', function () {
    expect((new DestinationPolicy)->approvedAddress('93.184.216.34', 993))->toBe('93.184.216.34');
    expect((new DestinationPolicy)->approvedAddress('93.184.216.34', 143))->toBe('93.184.216.34');
});

it('rejects other ports and malformed hosts', function (string $host, int $port) {
    expect(fn () => (new DestinationPolicy)->approvedAddress($host, $port))->toThrow(ImapFailure::class);
})->with([
    'ssh port' => ['93.184.216.34', 22],
    'http port' => ['93.184.216.34', 80],
    'path in host' => ['evil.test/../x', 993],
    'space' => ['a b', 993],
    'url' => ['http://evil.test', 993],
    'too long' => [str_repeat('a', 300), 993],
]);

it('rejects loopback, private, link-local and reserved addresses', function (string $ip) {
    expect(fn () => (new DestinationPolicy)->approvedAddress($ip, 993))->toThrow(ImapFailure::class);
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.10', '172.16.4.4', '169.254.169.254', '0.0.0.0', '::1', 'fd00::1', 'fe80::1']);

it('permits private ranges only when the operator allowlists them', function () {
    config(['mailcenter.imap.private_allowlist' => ['10.20.0.0/16', 'fd12::/16']]);
    $policy = new DestinationPolicy;

    expect($policy->approvedAddress('10.20.5.5', 993))->toBe('10.20.5.5');
    expect(fn () => $policy->approvedAddress('10.21.0.1', 993))->toThrow(ImapFailure::class);
    expect(fn () => $policy->approvedAddress('127.0.0.1', 993))->toThrow(ImapFailure::class);
});
