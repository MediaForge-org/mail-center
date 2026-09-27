<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 synchronized INBOX only and the UI had no per-folder control, so existing sent=false values
 * are legacy defaults, not user choices. Enable discovered Sent folders and schedule those accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = DB::table('remote_folders')->where('role', 'sent')->where('selectable', true)
            ->where('sync_enabled', false)->whereNull('removed_at')->pluck('mail_account_id')->unique()->all();
        DB::table('remote_folders')->where('role', 'sent')->where('selectable', true)
            ->where('sync_enabled', false)->whereNull('removed_at')->update(['sync_enabled' => true]);
        if ($accounts !== []) {
            DB::table('mail_accounts')->whereIn('id', $accounts)->where('enabled', true)->where('sync_enabled', true)
                ->whereNull('deleted_at')->update(['next_sync_at' => now()]);
        }
    }

    public function down(): void
    {
        // Irreversible by design: the previous value was a default, not a recorded choice.
    }
};
