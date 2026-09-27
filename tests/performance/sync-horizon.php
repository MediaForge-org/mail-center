<?php

use Illuminate\Contracts\Console\Kernel;
use Laravel\Horizon\SupervisorCommandString;
use Laravel\Horizon\WorkerCommandString;
use Symfony\Component\Console\Input\ArgvInput;

// Every child supervisor/worker re-enters the same strict database guard and synthetic bindings.
putenv('REDIS_PREFIX=mailcenter-m311-isolated-');
$_ENV['REDIS_PREFIX'] = $_SERVER['REDIS_PREFIX'] = 'mailcenter-m311-isolated-';
putenv('HORIZON_PREFIX=mailcenter-m311-horizon:');
$_ENV['HORIZON_PREFIX'] = $_SERVER['HORIZON_PREFIX'] = 'mailcenter-m311-horizon:';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/sync-bindings.php';
WorkerCommandString::$command = 'exec @php tests/performance/sync-horizon.php horizon:work';
SupervisorCommandString::$command = 'exec @php tests/performance/sync-horizon.php horizon:supervisor';
config(['horizon.defaults' => array_intersect_key(config('horizon.defaults'), array_flip(['sync-high-supervisor', 'sync-supervisor']))]);
exit($app->handleCommand(new ArgvInput));
