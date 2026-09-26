<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Vite::useHotFile('/tmp/mailcenter-browser-no-hot');
config(['mailcenter.blobs.root' => '/tmp/mailcenter-browser-blobs']);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/build/') && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}
$app->handleRequest(Request::capture());
