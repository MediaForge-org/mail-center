<?php

use App\Accounts\Credentials\CredentialVault;
use App\Models\MailAccount;
use App\Models\User;
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
