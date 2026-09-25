<?php

namespace App\Accounts\Credentials;

use App\Models\MailAccount;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

class CredentialVault
{
    private function key(string $id): string
    {
        $configured = $id === config('mailcenter.credentials.key_id')
            ? config('mailcenter.credentials.key')
            : (config('mailcenter.credentials.previous_keys')[$id] ?? null);
        if (! is_string($configured) || ! str_starts_with($configured, 'base64:')) {
            throw new RuntimeException('Mail credential key is not configured.');
        }
        $key = base64_decode(substr($configured, 7), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Mail credential key must be 32 bytes.');
        }

        return $key;
    }

    public function encrypt(#[\SensitiveParameter] string $password): array
    {
        return $this->encryptPayload(['password' => $password]);
    }

    public function encryptPayload(#[\SensitiveParameter] array $payload): array
    {
        $id = (string) config('mailcenter.credentials.key_id');
        $ciphertext = (new Encrypter($this->key($id), 'aes-256-gcm'))
            ->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        return ['ciphertext' => $ciphertext, 'key_id' => $id];
    }

    public function decrypt(string $ciphertext, string $keyId): Secret
    {
        return new Secret((string) $this->decryptPayload($ciphertext, $keyId)['password']);
    }

    public function decryptPayload(string $ciphertext, string $keyId): array
    {
        $json = (new Encrypter($this->key($keyId), 'aes-256-gcm'))->decryptString($ciphertext);

        return json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    }

    public function forAccount(MailAccount $account): Secret
    {
        $row = $account->credentials()->where('purpose', 'incoming')->firstOrFail();

        return $this->decrypt($row->ciphertext, $row->key_id);
    }
}
