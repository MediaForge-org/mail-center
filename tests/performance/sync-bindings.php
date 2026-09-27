<?php

// Required only by guarded fixture entrypoints, never by the application bootstrap.
use App\Accounts\Credentials\CredentialVault;
use App\Connectors\Imap\DestinationPolicy;
use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\PinnedImapStream;
use App\Sync\Idle\IdleClient;
use App\Sync\Idle\LibraryIdleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\LatencyImapClient;

config([
    'mailcenter.blobs.root' => '/tmp/mailcenter-m311-blobs',
    'mailcenter.credentials.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'mailcenter.credentials.key_id' => 'v1', 'mailcenter.credentials.previous_keys' => [],
    'cache.default' => 'array',
    'database.redis.options.prefix' => 'mailcenter-m311-isolated-',
    'horizon.prefix' => 'mailcenter-m311-horizon:',
]);
app()->bind(ImapClient::class, LatencyImapClient::class);
app()->bind(IdleClient::class, fn () => new LibraryIdleClient(
    app(CredentialVault::class),
    new class extends DestinationPolicy
    {
        public function approvedAddress(string $host, int $port): string
        {
            return '127.0.0.1';
        }
    },
    fn () => new class('127.0.0.1', 'synthetic.test') extends PinnedImapStream
    {
        public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
        {
            // Only this pre-boot-guarded fixture substitutes local TCP for TLS.
            return parent::open('tcp', $host, 11430, 2, []);
        }
    },
));

RateLimiter::for('manual-sync', fn () => Limit::none());

if (Illuminate\Support\Facades\Redis::connection()->client()->getOption(Redis::OPT_PREFIX) !== 'mailcenter-m311-isolated-') {
    throw new RuntimeException('Unsafe latency queue namespace.');
}
