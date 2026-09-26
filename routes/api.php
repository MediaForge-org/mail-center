<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountSyncController;
use App\Http\Controllers\Api\ConnectionTestController;
use App\Http\Controllers\Api\MessageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'id' => $request->user()->getAuthIdentifier(),
            'name' => $request->user()->name,
            'email' => $request->user()->email,
        ]);
    });

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/{id}', [MessageController::class, 'show'])->whereNumber('id');
    Route::get('/messages/{id}/render', [MessageController::class, 'render'])->whereNumber('id');
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::post('/accounts/test-connection', [ConnectionTestController::class, 'store'])->middleware('throttle:connection-test');
    Route::get('/connection-tests/{id}', [ConnectionTestController::class, 'show'])->whereNumber('id');
    Route::patch('/accounts/{id}', [AccountController::class, 'update'])->whereNumber('id');
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->whereNumber('id');
    Route::put('/accounts/{id}/credentials', [AccountController::class, 'credentials'])->whereNumber('id');
    Route::post('/accounts/{id}/sync', [AccountSyncController::class, 'sync'])->whereNumber('id')->middleware('throttle:manual-sync');
    Route::get('/accounts/{id}/sync-runs', [AccountSyncController::class, 'runs'])->whereNumber('id');
    Route::get('/accounts/{id}/remote-folders', [AccountSyncController::class, 'folders'])->whereNumber('id');
    Route::patch('/remote-folders/{id}', [AccountSyncController::class, 'updateFolder'])->whereNumber('id');
});
