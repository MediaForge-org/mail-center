<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'user:create {--name=} {--email=}';

    protected $description = 'Create an operator-managed MailCenter user';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
        $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $password = (string) $this->secret('Password (at least 12 characters)');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12) {
            $this->error('Provide a name, a valid email, and a password of at least 12 characters.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info('User created.');

        return self::SUCCESS;
    }
}
