<?php

namespace App\Jobs;

use App\Accounts\Credentials\CredentialVault;
use App\Accounts\Credentials\Secret;
use App\Connectors\Imap\ImapClient;
use App\Connectors\Imap\ImapFailure;
use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Tests IMAP settings held encrypted in connection_tests; the plaintext never enters Redis. */
class TestConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $testId) {}

    public function handle(ImapClient $client, CredentialVault $vault): void
    {
        $claimed = DB::table('connection_tests')->where('id', $this->testId)->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->update(['status' => 'running', 'lease_expires_at' => now()->addSeconds(120)]);
        if ($claimed === 0) {
            return;
        }
        /** @var object{encrypted_settings: string, key_id: string} $row */
        $row = DB::table('connection_tests')->find($this->testId);
        $status = 'failed';
        $code = ImapFailure::TRANSIENT;
        try {
            $settings = $vault->decryptPayload($row->encrypted_settings, $row->key_id);
            $client->connect(
                new MailAccount(['incoming' => [
                    'host' => $settings['host'], 'port' => $settings['port'],
                    'security' => $settings['security'], 'username' => $settings['username'],
                ]]),
                new Secret($settings['password']),
            );
            $client->folders();
            $status = 'succeeded';
            $code = 'ok';
        } catch (ImapFailure $failure) {
            $code = $failure->category;
        } catch (Throwable $e) {
            Log::error('Connection test failed unexpectedly.', ['exception' => $e::class]);
        } finally {
            try {
                $client->close();
            } catch (Throwable) {
            }
            DB::table('connection_tests')->where('id', $this->testId)->update([
                'status' => $status, 'result_code' => $code, 'encrypted_settings' => null,
                'lease_expires_at' => null, 'finished_at' => now(),
            ]);
        }
    }
}
