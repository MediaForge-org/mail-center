<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$pid = null;
foreach (glob('/proc/[0-9]*/cmdline') as $path) {
    $command = str_replace("\0", ' ', (string) @file_get_contents($path));
    if (str_starts_with($command, 'php tests/performance/sync-worker.php watch')) {
        $pid = basename(dirname($path));
        break;
    }
}
if ($pid === null) {
    throw new RuntimeException('Isolated watcher not running.');
}
$ticks = static function () use ($pid): int {
    $parts = preg_split('/\s+/', trim(file_get_contents('/proc/'.$pid.'/stat')));

    return (int) $parts[13] + (int) $parts[14];
};
$hz = (int) trim(shell_exec('getconf CLK_TCK'));
$start = microtime(true);
$before = $ticks();
sleep(30);
$elapsed = microtime(true) - $start;
$cpu = ($ticks() - $before) / $hz;
preg_match('/VmRSS:\s+(\d+)/', file_get_contents('/proc/'.$pid.'/status'), $rss);
echo json_encode([
    'seconds' => $elapsed, 'cpu_seconds' => $cpu, 'one_core_percent' => $cpu / $elapsed * 100,
    'rss_kib' => (int) $rss[1],
    'watch_connections' => DB::table('sync_watchers')->where('state', 'watching')->where('lease_until', '>', now())->count(),
], JSON_PRETTY_PRINT).PHP_EOL;
