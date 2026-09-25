<?php

namespace App\Http\Controllers\Api;

use App\Accounts\Credentials\CredentialVault;
use App\Connectors\Imap\ImapFailure;
use App\Http\Controllers\Controller;
use App\Jobs\TestConnectionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectionTestController extends Controller
{
    public function store(Request $request, CredentialVault $vault, AccountController $accounts): JsonResponse
    {
        $data = $request->validate(collect($accounts->rules(true))->only(['host', 'port', 'security', 'username', 'password'])->all());
        $encrypted = $vault->encryptPayload([
            'host' => strtolower($data['host']), 'port' => (int) $data['port'],
            'security' => $data['security'], 'username' => $data['username'], 'password' => $data['password'],
        ]);
        $id = DB::table('connection_tests')->insertGetId([
            'user_id' => $request->user()->id, 'encrypted_settings' => $encrypted['ciphertext'],
            'key_id' => $encrypted['key_id'], 'status' => 'pending',
            'expires_at' => now()->addMinutes(10), 'created_at' => now(),
        ]);
        TestConnectionJob::dispatch($id);

        return response()->json(['data' => ['id' => $id, 'status' => 'pending']], 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = DB::table('connection_tests')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_if($row === null, 404);
        $status = $row->status;
        if (in_array($status, ['pending', 'running'], true) && now()->gt($row->expires_at)) {
            $status = 'expired';
        }
        $code = $row->result_code;

        return response()->json(['data' => [
            'id' => $row->id, 'status' => $status, 'result_code' => $code,
            'message' => $status === 'succeeded' ? 'Connection succeeded.'
                : ($status === 'failed' ? ImapFailure::safeMessage((string) $code) : null),
        ]]);
    }
}
