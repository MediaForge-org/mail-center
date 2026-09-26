<?php

use App\Accounts\Credentials\CredentialVault;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/** Test helpers shared by the account and synchronization suites. */
function makeAccount(User $user, array $overrides = [], string $password = 'imap-secret-pw'): MailAccount
{
    $account = MailAccount::query()->create(array_merge([
        'user_id' => $user->id, 'provider' => 'imap', 'display_name' => 'Work', 'email_address' => 'me@example.test',
        'short_label' => 'WO',
        'incoming' => ['host' => 'imap.example.test', 'port' => 993, 'security' => 'tls', 'username' => 'me@example.test', 'verify_tls' => true],
        'next_sync_at' => now()->subMinute(),
    ], $overrides));
    $account->credentials()->create(['purpose' => 'incoming', 'kind' => 'password', ...app(CredentialVault::class)->encrypt($password)]);

    return $account;
}

function listMessage(User $user, int $accountId, string $subject, string $date, array $overrides = []): int
{
    $sha = hash('sha256', $user->id.$accountId.$subject.microtime(true).random_int(1, PHP_INT_MAX));
    DB::table('blobs')->insert(['sha256' => $sha, 'size_bytes' => 1, 'disk' => 'local', 'path' => 'private/'.$sha, 'created_at' => now()]);

    return DB::table('messages')->insertGetId(array_merge([
        'user_id' => $user->id,
        'mail_account_id' => $accountId,
        'dedupe_key' => 'raw-v1:'.$sha,
        'subject' => $subject,
        'from_name' => 'Sender',
        'from_address' => 'sender@example.test',
        'to' => json_encode([['name' => 'Recipient', 'address' => 'recipient@example.test']]),
        'snippet' => 'Safe preview',
        'sort_date' => $date,
        'received_at' => $date,
        'size_bytes' => 1,
        'raw_blob_sha256' => $sha,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}
