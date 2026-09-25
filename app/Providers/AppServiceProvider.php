<?php

namespace App\Providers;

use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\LibraryImapClient;
use App\Sync\AccountSyncDriver;
use App\Sync\AccountSyncLock;
use App\Sync\Imap\ImapSyncDriver;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImapClient::class, LibraryImapClient::class);
        $this->app->bind(AccountSyncDriver::class, ImapSyncDriver::class);
        $this->app->singleton(AccountSyncLock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // M1 has no operator role model; keep the dashboard closed until one exists.
        Horizon::auth(fn () => false);
    }
}
