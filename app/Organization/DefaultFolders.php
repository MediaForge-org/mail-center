<?php

namespace App\Organization;

use Illuminate\Support\Facades\DB;

/** Seeded local custom folders (04-organization-semantics.md §2.1). Never remote IMAP folders. */
final class DefaultFolders
{
    public const SEED = ['Reloads', 'Support', 'Withdrawals', 'Verification', 'Done'];

    /** Idempotent: skips any name the user already has (case-insensitive), never creates duplicates. */
    public static function seedForUser(int $userId): void
    {
        $existing = DB::table('folders')->where('user_id', $userId)
            ->pluck('name')->map(fn ($name) => mb_strtolower($name))->all();
        $position = (int) (DB::table('folders')->where('user_id', $userId)->max('position') ?? -1) + 1;
        $now = now();
        $rows = [];
        foreach (self::SEED as $name) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            $rows[] = ['user_id' => $userId, 'name' => $name, 'system_role' => null, 'position' => $position++, 'created_at' => $now, 'updated_at' => $now];
            $existing[] = mb_strtolower($name);
        }
        if ($rows !== []) {
            DB::table('folders')->insert($rows);
        }
    }

    /** Safe to run repeatedly; used by the backfill migration for users created before M4.1. */
    public static function backfillAllUsers(): void
    {
        DB::table('users')->orderBy('id')->pluck('id')->each(fn ($id) => self::seedForUser((int) $id));
    }
}
