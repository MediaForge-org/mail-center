<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => view('app'));

        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        $perUser = fn (Request $request) => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
        RateLimiter::for('connection-test', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('manual-sync', fn (Request $request) => Limit::perMinute(6)->by($perUser($request)));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
        );
    }
}
