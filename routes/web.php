<?php

use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\RemoteImageController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/mail');

Route::get('/ready', function () {
    try {
        DB::select('SELECT 1');
        Redis::connection('default')->ping();
        Redis::connection('cache')->ping();

        return response()->json(['status' => 'ready']);
    } catch (Throwable $exception) {
        report($exception);

        return response()->json(['status' => 'unavailable'], 503);
    }
});

Route::view('/mail/{path?}', 'app')->where('path', '.*')->middleware('auth');

// Browser GET resources carry the web session cookie even with Referrer-Policy: no-referrer.
// They must not depend on Sanctum's Origin/Referer-based SPA detection.
Route::middleware(['auth:web', 'throttle:api'])->group(function () {
    Route::get('/api/image-proxy', [RemoteImageController::class, 'proxy']);
    Route::post('/api/messages/{id}/remote-images', [RemoteImageController::class, 'consent'])->whereNumber('id');
    Route::delete('/api/remote-image-consent', [RemoteImageController::class, 'revoke']);
    Route::get('/api/messages/{id}/render', [MessageController::class, 'render'])->whereNumber('id');
    Route::get('/api/messages/{id}/attachments/{attachment}', [MessageController::class, 'downloadAttachment'])->whereNumber(['id', 'attachment']);
    Route::get('/api/messages/{id}/inline/{attachment}', [MessageController::class, 'inlineAttachment'])->whereNumber(['id', 'attachment']);
});
