<?php

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

require dirname(__DIR__).'/vendor/autoload.php';

// PHPUnit applies phpunit.xml before loading this file. Fail before Pest, TestCase,
// RefreshDatabase, or migrate:fresh can initialize a database.
foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => 'postgres-test',
    'DB_PORT' => '5432',
    'DB_DATABASE' => 'mailcenter_test',
    'DB_URL' => '',
] as $name => $expected) {
    if (getenv($name) !== $expected) {
        throw new RuntimeException("Unsafe test environment: {$name} must be {$expected}.");
    }

    // PHPUnit's forced process value may coexist with stale Compose values in
    // PHP's arrays. Laravel's env() reads those arrays, so align them first.
    $_ENV[$name] = $expected;
    $_SERVER[$name] = $expected;
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->bootstrapWith([
    LoadEnvironmentVariables::class,
    LoadConfiguration::class,
]);
$settings = $app['config'];

if ($settings->get('app.env') !== 'testing' || $settings->get('database.default') !== 'pgsql') {
    throw new RuntimeException('Unsafe test configuration: wrong app environment or database driver.');
}

foreach (['pgsql', 'sync_lock'] as $connection) {
    $config = $settings->get("database.connections.{$connection}");
    if (($config['host'] ?? null) !== 'postgres-test'
        || (string) ($config['port'] ?? '') !== '5432'
        || ($config['database'] ?? null) !== 'mailcenter_test'
        || ! empty($config['url'])
        || isset($config['read'])
        || isset($config['write'])) {
        throw new RuntimeException("Unsafe test database configuration: {$connection} must target postgres-test/mailcenter_test.");
    }
}

$database = $settings->get('database.connections.pgsql');
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $database['host'], $database['port'], $database['database']),
    $database['username'],
    $database['password'],
    [PDO::ATTR_TIMEOUT => 3],
);
if ($pdo->query('select current_database()')->fetchColumn() !== 'mailcenter_test') {
    throw new RuntimeException('Unsafe test database connection: server selected a non-test database.');
}
