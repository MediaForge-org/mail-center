<?php

namespace App\Sync\Idle;

use App\Connectors\Imap\ImapFailure;
use App\Models\MailAccount;
use App\Sync\SyncRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Fixed-size multiplexed connection pool. No transaction or writer lock spans an IDLE wait. */
class IdleWatcher
{
    private string $owner;

    /** @var array<int, array{client:IdleClient, account:int, fingerprint:string, renewed:float}> */
    private array $sessions = [];

    public function __construct(private readonly SyncRequests $requests)
    {
        $this->owner = (string) Str::uuid();
    }

    public function reconcile(int $capacity = 32): void
    {
        $wanted = [];
        $accounts = MailAccount::query()->where('enabled', true)->where('sync_enabled', true)
            ->whereNotIn('sync_status', ['auth_failed', 'error', 'backing_off'])
            ->with(['credentials', 'remoteFolders' => fn ($q) => $q->where('selectable', true)->where('sync_enabled', true)
                ->whereNull('removed_at')->whereIn('role', ['inbox', 'sent'])->orderBy('id')])->get();
        foreach ($accounts as $account) {
            $credential = $account->credentials->firstWhere('purpose', 'incoming');
            if (! $credential) {
                continue;
            }
            $fingerprint = hash('sha256', json_encode($account->incoming).$credential->ciphertext.$credential->key_id);
            // At most one mailbox for each role; aliases cannot multiply connections.
            foreach ($account->remoteFolders->unique('role') as $folder) {
                $wanted[$folder->id] = [$account, $folder, $fingerprint];
            }
        }
        foreach ($this->sessions as $id => $session) {
            if (! isset($wanted[$id]) || $wanted[$id][2] !== $session['fingerprint']) {
                $this->drop($id);

                continue;
            }
            $renewed = DB::table('sync_watchers')->where('remote_folder_id', $id)->where('owner', $this->owner)
                ->where('lease_until', '>', now())->update(['lease_until' => now()->addSeconds(30)]);
            if (! $renewed) {
                $this->drop($id);
            }
        }
        foreach ($wanted as $id => [$account, $folder, $fingerprint]) {
            if (isset($this->sessions[$id]) || count($this->sessions) >= $capacity) {
                continue;
            }
            DB::table('sync_watchers')->insertOrIgnore([
                'remote_folder_id' => $id, 'mail_account_id' => $account->id, 'fingerprint' => $fingerprint,
            ]);
            $claimed = DB::table('sync_watchers')->where('remote_folder_id', $id)
                ->where(fn ($q) => $q->whereNull('owner')->orWhere('lease_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())->orWhere('fingerprint', '<>', $fingerprint))
                ->update(['owner' => $this->owner, 'lease_until' => now()->addSeconds(30), 'fingerprint' => $fingerprint]);
            if (! $claimed) {
                continue;
            }
            $client = app(IdleClient::class);
            $this->sessions[$id] = ['client' => $client, 'account' => $account->id, 'fingerprint' => $fingerprint, 'renewed' => microtime(true)];
            try {
                if (! $client->open($account, $folder)) {
                    $this->drop($id, 'unsupported', 3600);
                    break;
                }
                if (! $this->owns($id)) {
                    $this->drop($id);

                    continue;
                }
                DB::table('sync_watchers')->where('remote_folder_id', $id)->where('owner', $this->owner)->update(['attempts' => 0]);
                $this->state($id, 'watching');
                // Close the EXAMINE/IDLE subscription gap using the existing incremental engine.
                $this->requests->request($account->id, 'idle');
            } catch (Throwable $error) {
                $this->failed($id, $error);
            }
            // One handshake per tick prevents reconnect storms from blocking established watches.
            break;
        }
    }

    /** Blocks in select, so idle CPU is negligible; notifications wake it immediately. */
    public function wait(int $seconds = 1): void
    {
        if ($this->sessions === []) {
            if ($seconds > 0) {
                sleep($seconds);
            }

            return;
        }
        $read = [];
        foreach ($this->sessions as $id => $session) {
            $read[$id] = $session['client']->socket();
        }
        $write = $except = [];
        $ready = @stream_select($read, $write, $except, $seconds);
        if ($ready === false) {
            return;
        } // signals interrupt select; the command handles shutdown
        foreach ($read as $id => $socket) {
            if (! isset($this->sessions[$id])) {
                continue;
            }
            try {
                if (! $this->owns($id)) {
                    $this->drop($id);

                    continue;
                }
                if ($this->sessions[$id]['client']->changed()) {
                    $this->requests->request($this->sessions[$id]['account'], 'idle');
                }
            } catch (Throwable $error) {
                $this->failed($id, $error);
            }
        }
        foreach ($this->sessions as $id => $session) {
            if (microtime(true) - $session['renewed'] < 1500) {
                continue;
            }
            try {
                if (! $this->owns($id)) {
                    $this->drop($id);

                    continue;
                }
                $session['client']->renew();
                $this->sessions[$id]['renewed'] = microtime(true);
                $this->requests->request($session['account'], 'idle');
            } catch (Throwable $error) {
                $this->failed($id, $error);
            }
        }
    }

    private function owns(int $id): bool
    {
        return DB::table('sync_watchers')->where('remote_folder_id', $id)->where('owner', $this->owner)
            ->where('lease_until', '>', now())->exists();
    }

    private function failed(int $id, Throwable $error): void
    {
        if (! $this->owns($id)) {
            $this->drop($id);

            return;
        }
        $auth = $error instanceof ImapFailure && $error->category === ImapFailure::AUTH;
        $accountId = $this->sessions[$id]['account'];
        $current = MailAccount::query()->with('credentials')->find($accountId);
        $credential = $current?->credentials->firstWhere('purpose', 'incoming');
        if (! $current || ! $current->enabled || ! $current->sync_enabled || ! $credential
            || hash('sha256', json_encode($current->incoming).$credential->ciphertext.$credential->key_id) !== $this->sessions[$id]['fingerprint']) {
            $this->drop($id);

            return;
        }
        $attempts = (int) DB::table('sync_watchers')->where('remote_folder_id', $id)->value('attempts') + 1;
        DB::table('sync_watchers')->where('remote_folder_id', $id)->where('owner', $this->owner)->update(['attempts' => $attempts]);
        if ($auth) {
            MailAccount::query()->whereKey($accountId)->update([
                'sync_status' => 'auth_failed', 'next_sync_at' => null,
                'last_error_code' => ImapFailure::AUTH, 'last_error_message' => ImapFailure::safeMessage(ImapFailure::AUTH), 'last_error_at' => now(),
            ]);
        }
        $this->drop($id, $auth ? 'auth_failed' : 'backoff', $auth ? 3600 : min(300, 2 ** min($attempts, 8)));
    }

    private function state(int $id, string $state): void
    {
        // Never write mail_accounts from the watcher: account status is read live from leases, and that
        // row is the sync engine's serialization point (a touch here raced its FOR UPDATE and deadlocked).
        DB::table('sync_watchers')->where('remote_folder_id', $id)->where('owner', $this->owner)
            ->where('state', '<>', $state)->update(['state' => $state]);
    }

    private function drop(int $id, string $state = 'polling', int $retry = 0): void
    {
        if (! isset($this->sessions[$id])) {
            return;
        }
        try {
            $this->sessions[$id]['client']->close();
        } catch (Throwable) {
            // Already disconnected. Never log protocol exceptions.
        }
        $this->state($id, $state);
        DB::table('sync_watchers')->where('remote_folder_id', $id)->where('owner', $this->owner)->update([
            'owner' => null, 'lease_until' => null, 'next_attempt_at' => now()->addSeconds($retry),
        ]);
        unset($this->sessions[$id]);
    }

    public function close(): void
    {
        foreach (array_keys($this->sessions) as $id) {
            $this->drop($id);
        }
    }

    public function connections(): int
    {
        return count($this->sessions);
    }
}
