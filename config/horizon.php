<?php

return [
    'use' => 'default',
    'path' => 'horizon',
    'middleware' => ['web'],
    'waits' => ['mail_sync:sync' => 120, 'redis:default' => 60],
    'defaults' => [
        'sync-supervisor' => [
            'connection' => 'mail_sync', 'queue' => ['sync'], 'balance' => 'simple',
            'maxProcesses' => 2, 'memory' => 512, 'tries' => 1, 'timeout' => 600,
            'maxJobs' => 50, 'maxTime' => 3600,
        ],
        'default-supervisor' => [
            'connection' => 'redis', 'queue' => ['default'], 'balance' => 'simple',
            'maxProcesses' => 1, 'memory' => 256, 'tries' => 1, 'timeout' => 60,
            'maxJobs' => 200, 'maxTime' => 3600,
        ],
    ],
    'environments' => [
        'production' => ['sync-supervisor' => ['maxProcesses' => 4]],
        'local' => ['sync-supervisor' => ['maxProcesses' => 2]],
        'testing' => ['sync-supervisor' => ['maxProcesses' => 1]],
    ],
];
