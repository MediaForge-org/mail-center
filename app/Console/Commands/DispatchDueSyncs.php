<?php

namespace App\Console\Commands;

use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueSyncs extends Command
{
    protected $signature = 'sync:dispatch-due';

    protected $description = 'Queue synchronization for accounts that are due and recover stale runs';

    public function handle(): int
    {
        $this->recoverStaleRuns();
        DB::table('connection_tests')->where('created_at', '<', now()->subDay())->delete();

        $count = 0;
        MailAccount::query()->where('enabled', true)->where('sync_enabled', true)
            ->where('sync_status', '!=', 'auth_failed')->whereNotNull('next_sync_at')
            ->where('next_sync_at', '<=', now())
            ->whereHas('credentials')
            ->orderBy('next_sync_at')->limit(500)->pluck('id')
            ->each(function (int $id) use (&$count) {
                SyncAccountJob::dispatch($id);
                $count++;
            });
        $this->info("Dispatched {$count} account sync job(s).");

        return self::SUCCESS;
    }

    /** A run still "running" after 20 minutes belongs to a crashed worker; its checkpoints allow resuming. */
    private function recoverStaleRuns(): void
    {
        $stale = DB::table('sync_runs')->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(20))->get();
        foreach ($stale as $run) {
            DB::table('sync_runs')->where('id', $run->id)->update([
                'status' => 'failed', 'finished_at' => now(),
                'error_code' => 'stale_run', 'error_message' => 'The run stopped unexpectedly and will resume.',
            ]);
            MailAccount::query()->whereKey($run->mail_account_id)->where('sync_status', 'syncing')
                ->where('enabled', true)->where('sync_enabled', true)
                ->update(['sync_status' => 'idle', 'next_sync_at' => now()]);
        }
    }
}
