<?php

use App\Http\Controllers\Api\MessageController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::findOrFail((int) $argv[1]);
$request = Request::create('/api/changes', 'GET');
$request->setUserResolver(fn () => $user);
$controller = app(MessageController::class);
$samples = [];
for ($i = -5; $i < 100; $i++) {
    $start = hrtime(true);
    $response = $controller->changes($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Poll failed.');
    }
    if ($i >= 0) {
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
}
echo json_encode($samples);
