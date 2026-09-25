<?php

namespace App\Http\Controllers\Api;

use App\Accounts\AccountPresenter;
use App\Accounts\Credentials\CredentialVault;
use App\Http\Controllers\Controller;
use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = MailAccount::query()->where('user_id', $request->user()->id)
            ->orderBy('position')->orderBy('id')->get();

        return response()->json(['data' => $accounts->map(fn (MailAccount $account) => $this->present($account))]);
    }

    public function store(Request $request, CredentialVault $vault): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $duplicate = MailAccount::query()->where('user_id', $request->user()->id)
            ->where('email_address', $data['email_address'])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['email_address' => 'This mailbox is already connected.']);
        }
        $encrypted = $vault->encrypt($data['password']);
        $account = DB::transaction(function () use ($request, $data, $encrypted) {
            $account = MailAccount::query()->create([
                'user_id' => $request->user()->id, 'provider' => 'imap',
                'display_name' => $data['display_name'],
                'email_address' => $data['email_address'],
                'short_label' => mb_strtoupper(mb_substr($data['display_name'], 0, 2)),
                'incoming' => $this->incoming($data),
                'next_sync_at' => now(),
            ]);
            $account->credentials()->create(['purpose' => 'incoming', 'kind' => 'password', ...$encrypted]);

            return $account;
        });

        SyncAccountJob::dispatch($account->id, 'initial');

        return response()->json(['data' => $this->present($account->refresh())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'sync_enabled' => ['sometimes', 'boolean'],
            'sync_interval_seconds' => ['sometimes', 'integer', 'min:120', 'max:3600'],
        ]);
        if (array_key_exists('display_name', $data)) {
            $data['short_label'] = mb_strtoupper(mb_substr($data['display_name'], 0, 2));
        }
        if (array_key_exists('enabled', $data) || array_key_exists('sync_enabled', $data)) {
            $active = ($data['enabled'] ?? $account->enabled) && ($data['sync_enabled'] ?? $account->sync_enabled);
            $data['next_sync_at'] = $active && $account->sync_status !== 'auth_failed' ? now() : null;
        }
        $account->update($data);

        return response()->json(['data' => $this->present($account->refresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $request->validate(['current_password' => ['required', 'string']]);
        abort_unless(Hash::check($request->input('current_password'), $request->user()->password), 422, 'Current password is incorrect.');
        DB::transaction(function () use ($account) {
            $account->update(['enabled' => false, 'sync_enabled' => false, 'next_sync_at' => null]);
            $account->credentials()->delete(); // the secret is not kept for a removed account
            $account->delete();
        });

        return response()->json(['message' => 'Account removed locally; remote mail was not changed.']);
    }

    public function credentials(Request $request, int $id, CredentialVault $vault): JsonResponse
    {
        $account = $this->owned($request, $id);
        $data = $request->validate([
            'password' => ['required', 'string', 'max:1024'],
            'current_password' => ['required', 'string'],
        ]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Current password is incorrect.');
        $account->credentials()->updateOrCreate(['purpose' => 'incoming'], [
            'kind' => 'password', ...$vault->encrypt($data['password']),
        ]);
        $account->update([
            'sync_status' => 'idle', 'consecutive_failures' => 0,
            'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null,
            'next_sync_at' => $account->enabled && $account->sync_enabled ? now() : null,
        ]);

        return response()->json(['has_password' => true]);
    }

    public function owned(Request $request, int $id): MailAccount
    {
        return MailAccount::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function present(MailAccount $account): array
    {
        return AccountPresenter::present($account);
    }

    public function rules(bool $withPassword): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'email_address' => ['required', 'email', 'max:254'],
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'],
            'port' => ['required', 'integer', Rule::in(config('mailcenter.imap.allowed_ports'))],
            'security' => ['required', Rule::in(['tls', 'starttls'])],
            'username' => ['required', 'string', 'max:254'],
            'password' => [$withPassword ? 'required' : 'sometimes', 'string', 'max:1024'],
        ];
    }

    public function incoming(array $data): array
    {
        return [
            'host' => strtolower($data['host']), 'port' => (int) $data['port'],
            'security' => $data['security'], 'username' => $data['username'],
            'verify_tls' => true,
        ];
    }
}
