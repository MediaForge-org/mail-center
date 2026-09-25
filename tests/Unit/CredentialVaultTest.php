<?php

use App\Accounts\Credentials\CredentialVault;
use App\Accounts\Credentials\Secret;
use Illuminate\Contracts\Encryption\DecryptException;

it('round-trips a secret and never stores it in the clear', function () {
    $vault = new CredentialVault;
    $stored = $vault->encrypt('p@ss-word-value');

    expect($stored['ciphertext'])->not->toContain('p@ss-word-value')->and($stored['key_id'])->toBe('v1');
    expect($vault->decrypt($stored['ciphertext'], $stored['key_id'])->password)->toBe('p@ss-word-value');
    expect($vault->encrypt('p@ss-word-value')['ciphertext'])->not->toBe($stored['ciphertext']); // random nonce
});

it('rejects tampered ciphertext and wrong keys', function () {
    $vault = new CredentialVault;
    $stored = $vault->encrypt('secret-value');
    $tampered = substr($stored['ciphertext'], 0, -4).'AAAA';
    expect(fn () => $vault->decrypt($tampered, 'v1'))->toThrow(DecryptException::class);

    config(['mailcenter.credentials.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    expect(fn () => $vault->decrypt($stored['ciphertext'], 'v1'))->toThrow(DecryptException::class);
});

it('supports key rotation through previous keys and refuses missing or short keys', function () {
    $vault = new CredentialVault;
    $old = $vault->encrypt('rotating');
    config([
        'mailcenter.credentials.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'mailcenter.credentials.key_id' => 'v2',
        'mailcenter.credentials.previous_keys' => ['v1' => 'base64:'.base64_encode(str_repeat('t', 32))],
    ]);
    expect($vault->decrypt($old['ciphertext'], 'v1')->password)->toBe('rotating');
    expect($vault->encrypt('new')['key_id'])->toBe('v2');

    config(['mailcenter.credentials.key' => 'base64:'.base64_encode('short')]);
    expect(fn () => $vault->encrypt('x'))->toThrow(RuntimeException::class);
    config(['mailcenter.credentials.key' => null]);
    expect(fn () => $vault->encrypt('x'))->toThrow(RuntimeException::class);
});

it('redacts secrets from debug output', function () {
    $secret = new Secret('hunter2-hunter2');
    expect(print_r($secret, true))->not->toContain('hunter2')->and(var_export((array) $secret, true))->toContain('hunter2');
    ob_start();
    var_dump($secret);
    expect(ob_get_clean())->not->toContain('hunter2');
});
