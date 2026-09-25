<?php

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
