<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->get('/me', function (Request $request) {
    return response()->json([
        'id' => $request->user()->getAuthIdentifier(),
        'name' => $request->user()->name,
        'email' => $request->user()->email,
    ]);
});
