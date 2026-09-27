<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\ConsoleOutput;

putenv('REDIS_PREFIX=mailcenter-m311-isolated-');
$_ENV['REDIS_PREFIX'] = $_SERVER['REDIS_PREFIX'] = 'mailcenter-m311-isolated-';
putenv('HORIZON_PREFIX=mailcenter-m311-horizon:');
$_ENV['HORIZON_PREFIX'] = $_SERVER['HORIZON_PREFIX'] = 'mailcenter-m311-horizon:';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/sync-bindings.php';
$mode = $argv[1] ?? 'sync-high';
if ($mode === 'watch') {
    Artisan::call('sync:watch', ['--connections' => 2], new ConsoleOutput);
} else {
    Artisan::call('queue:work', ['connection' => 'mail_sync', '--queue' => $mode, '--sleep' => 0, '--tries' => 0, '--timeout' => 600], new ConsoleOutput);
}
