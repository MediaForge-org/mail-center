<?php

namespace App\Accounts;

use App\Models\MailAccount;
use Illuminate\Support\Facades\DB;

/** The only shape in which an account leaves the server. It never includes credentials. */
class AccountPresenter
{
    public static function present(MailAccount $account): array
    {
        $incoming = $account->incoming;

        return [
            'id' => $account->id, 'provider' => $account->provider,
            'display_name' => $account->display_name, 'email_address' => $account->email_address,
            'short_label' => $account->short_label, 'color' => $account->color,
            'incoming' => [
                'host' => $incoming['host'] ?? null, 'port' => $incoming['port'] ?? null,
                'security' => $incoming['security'] ?? null, 'username' => $incoming['username'] ?? null,
            ],
            'write_back_seen' => $account->write_back_seen,
            'seen_writeback_error' => $account->seen_writeback_error,
            'enabled' => $account->enabled, 'sync_enabled' => $account->sync_enabled,
            'sync_interval_seconds' => $account->sync_interval_seconds,
            'sync_status' => $account->sync_status, 'next_sync_at' => $account->next_sync_at,
            'last_sync_started_at' => $account->last_sync_started_at,
            'last_sync_finished_at' => $account->last_sync_finished_at,
            'last_successful_sync_at' => $account->last_successful_sync_at,
            'consecutive_failures' => $account->consecutive_failures,
            'last_error_code' => $account->last_error_code, 'last_error_message' => $account->last_error_message,
            'last_error_at' => $account->last_error_at,
            'has_password' => $account->credentials()->where('purpose', 'incoming')->exists(),
            'synced_message_count' => DB::table('messages')->where('mail_account_id', $account->id)->count(),
            'quarantined_message_count' => DB::table('sync_failures')
                ->join('remote_folders', 'remote_folders.id', '=', 'sync_failures.remote_folder_id')
                ->where('remote_folders.mail_account_id', $account->id)
                ->whereIn('sync_failures.status', ['retryable', 'manual'])->count(),
        ];
    }
}
