<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use App\Models\RemoteFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountSyncController extends Controller
{
    public function sync(Request $request, int $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        if (! $account->enabled || ! $account->sync_enabled) {
            return response()->json(['message' => 'Synchronization is disabled for this account.'], 409);
        }
        if ($account->sync_status === 'auth_failed') {
            return response()->json(['message' => 'The server rejected the saved password. Enter a new password first.'], 409);
        }
        if (in_array($account->sync_status, ['backing_off', 'error'], true) && $account->next_sync_at?->isFuture()) {
            return response()->json([
                'message' => 'Synchronization is waiting after an error; it will retry automatically.',
                'next_sync_at' => $account->next_sync_at,
            ], 409);
        }
        $account->update(['next_sync_at' => now()]);
        SyncAccountJob::dispatch($account->id, 'manual'); // unique per account: repeated clicks are harmless

        return response()->json(['message' => 'Synchronization queued.'], 202);
    }

    public function runs(Request $request, int $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $runs = DB::table('sync_runs')->where('mail_account_id', $account->id)
            ->orderByDesc('id')->limit(20)
            ->get(['id', 'trigger', 'started_at', 'finished_at', 'status', 'stats', 'error_code', 'error_message'])
            ->map(fn ($run) => [...(array) $run, 'stats' => json_decode($run->stats, true)]);

        return response()->json(['data' => $runs]);
    }

    public function folders(Request $request, int $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $folders = $account->remoteFolders()->whereNull('removed_at')->orderBy('name')->get()
            ->map(fn (RemoteFolder $folder) => $this->presentFolder($folder));

        return response()->json(['data' => $folders]);
    }

    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $folder = RemoteFolder::query()->whereIn('mail_account_id', MailAccount::query()
            ->where('user_id', $request->user()->id)->select('id'))->findOrFail($id);
        $data = $request->validate(['sync_enabled' => ['required', 'boolean']]);
        abort_if($data['sync_enabled'] && ! $folder->selectable, 422, 'This folder cannot be synchronized.');
        $folder->update(['sync_enabled' => $data['sync_enabled']]);

        return response()->json(['data' => $this->presentFolder($folder)]);
    }

    private function presentFolder(RemoteFolder $folder): array
    {
        return [
            'id' => $folder->id, 'name' => $folder->name, 'role' => $folder->role,
            'selectable' => $folder->selectable, 'sync_enabled' => $folder->sync_enabled,
            'backfill_complete' => $folder->backfill_completed_at !== null,
            'last_synced_at' => $folder->last_synced_at,
        ];
    }

    private function owned(Request $request, int $id): MailAccount
    {
        return MailAccount::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
