<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Secondary fail-closed check if a runner bypasses phpunit.xml's bootstrap.
        // This must stay before parent::setUp(), which invokes RefreshDatabase.
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_HOST') !== 'postgres-test' || getenv('DB_DATABASE') !== 'mailcenter_test'
            || getenv('DB_URL') !== '') {
            throw new \RuntimeException('Unsafe backend test database target.');
        }

        parent::setUp();

        // Tests share a long-lived Redis and reuse database ids after migrate:fresh, so persisted
        // unique-job locks and throttle counters would leak between runs. Keep them in memory.
        config(['cache.default' => 'array']);
        // Never depend on (or touch) a developer's real credential key.
        config([
            'mailcenter.credentials.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'mailcenter.credentials.key_id' => 'v1',
            'mailcenter.credentials.previous_keys' => [],
        ]);
        // Queue::fake() honours after_commit and would hold jobs forever inside the test transaction.
        config(['queue.connections.mail_sync.after_commit' => false, 'queue.connections.redis.after_commit' => false]);
    }
}
