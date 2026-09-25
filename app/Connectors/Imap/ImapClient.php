<?php

namespace App\Connectors\Imap;

use App\Accounts\Credentials\Secret;
use App\Models\MailAccount;

interface ImapClient
{
    public function connect(MailAccount $account, Secret $secret): void;

    /** @return array<int, array{raw_name:string, name:string, delimiter:?string, attributes:array, role:string, selectable:bool}> */
    public function folders(): array;

    /** @return array{uidvalidity:int, uidnext:int, exists:int} */
    public function examine(string $rawName): array;

    /** @return int[] */
    public function search(int $low, int $high): array;

    /** @return array{flags:array, internal_date:?string, size:int}|null */
    public function metadata(int $uid): ?array;

    /** @return array{raw:string, flags:array, internal_date:?string, size:int}|null */
    public function fetch(int $uid): ?array;

    /** @return array<int, array> UID => flags */
    public function flags(int $low, int $high): array;

    public function close(): void;
}
