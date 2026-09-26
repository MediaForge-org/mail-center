<?php

use App\Messages\RemoteImages\Resolver;
use App\Messages\RemoteImages\Transport;

// Loaded only by the strict database-guarded test router. No production policy exceptions exist.
// Simulate public DNS; send the validated hop to a local fixture instead of the public Internet.
$app->bind(Resolver::class, fn () => new class extends Resolver
{
    public function addresses(string $host, float $timeout): array
    {
        if ($host !== 'attacker.invalid') {
            throw new RuntimeException('Unexpected test destination.');
        }

        return ['93.184.216.34'];
    }
});
$app->bind(Transport::class, fn () => new class extends Transport
{
    public function get(string $url, string $host, int $port, string $ip, float $timeout): array
    {
        if ($host !== 'attacker.invalid' || $ip !== '93.184.216.34') {
            throw new RuntimeException('Unexpected test destination.');
        }

        return parent::get('http://fixture.test:8073/pixel', 'fixture.test', 8073, '127.0.0.1', $timeout);
    }
});
