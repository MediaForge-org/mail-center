<?php

namespace App\Accounts\Credentials;

final readonly class Secret
{
    public function __construct(#[\SensitiveParameter] public string $password) {}

    public function __debugInfo(): array
    {
        return ['password' => '[REDACTED]'];
    }
}
