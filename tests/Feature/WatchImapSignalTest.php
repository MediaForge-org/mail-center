<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A real child process: framework signal helpers exited 143 and skipped lease release on SIGTERM. */
it('sync:watch exits cleanly (0) on SIGTERM so its finally block can release leases', function () {
    if (! function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is required.');
    }
    $process = proc_open([PHP_BINARY, base_path('artisan'), 'sync:watch'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    expect($process)->not->toBeFalse();
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        usleep(100_000); // let it boot and reach the watch loop
    }
    expect(proc_get_status($process)['running'])->toBeTrue();
    proc_terminate($process, SIGTERM);
    $exit = null;
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (! $status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        usleep(100_000);
    }
    proc_close($process);
    expect($exit)->toBe(0);
});
