<?php

namespace App\Organization;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SystemFolders
{
    public static function createForUser(int $userId): void
    {
        $now = now();
        DB::table('folders')->insertOrIgnore([
            ['user_id' => $userId, 'name' => 'Inbox', 'system_role' => 'inbox', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $userId, 'name' => 'Sent', 'system_role' => 'sent', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $userId, 'name' => 'Archive', 'system_role' => 'archive', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public static function idFor(int $userId, string $role): int
    {
        $id = DB::table('folders')->where('user_id', $userId)->where('system_role', $role)->value('id');
        if ($id === null) {
            throw new RuntimeException("Missing {$role} system folder for user {$userId}.");
        }

        return (int) $id;
    }

    /** Assign M2 rows by their first committed location (lowest location id). Safe to repeat. */
    public static function backfillMissing(): void
    {
        DB::statement(<<<'SQL'
            UPDATE messages AS m
            SET folder_id = f.id
            FROM folders AS f
            WHERE m.folder_id IS NULL
              AND f.user_id = m.user_id
              AND f.system_role = COALESCE((
                  SELECT CASE
                      WHEN rf.role = 'inbox' THEN 'inbox'
                      WHEN rf.role = 'sent' THEN 'sent'
                      ELSE 'archive'
                  END
                  FROM message_locations AS ml
                  JOIN remote_folders AS rf ON rf.id = ml.remote_folder_id
                  WHERE ml.message_id = m.id
                  ORDER BY ml.id ASC
                  LIMIT 1
              ), 'archive')
            SQL);
    }
}
