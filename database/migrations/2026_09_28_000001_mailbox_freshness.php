<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mailbox_changed() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE owner_id bigint;
            BEGIN
                FOR owner_id IN
                    SELECT DISTINCT coalesce((to_jsonb(r)->>'user_id')::bigint, m.user_id)
                    FROM changed_rows r LEFT JOIN messages m ON m.id = (to_jsonb(r)->>'message_id')::bigint
                    ORDER BY 1
                LOOP
                    IF owner_id IS NOT NULL THEN
                        INSERT INTO user_change_versions(user_id, version) VALUES(owner_id, 1)
                        ON CONFLICT (user_id) DO UPDATE SET version = user_change_versions.version + 1;
                    END IF;
                END LOOP;
                RETURN NULL;
            END $$;
            SQL);
        foreach (['messages', 'mail_accounts', 'message_bodies', 'attachments', 'remote_flag_changes', 'remote_content_allowlist', 'folders'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $transition = $event === 'DELETE' ? 'OLD' : 'NEW';
                DB::statement("CREATE TRIGGER mailbox_{$table}_{$event} AFTER {$event} ON {$table} REFERENCING {$transition} TABLE AS changed_rows FOR EACH STATEMENT EXECUTE FUNCTION mailbox_changed()");
            }
        }
    }

    public function down(): void
    {
        foreach (['messages', 'mail_accounts', 'message_bodies', 'attachments', 'remote_flag_changes', 'remote_content_allowlist', 'folders'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                DB::statement("DROP TRIGGER mailbox_{$table}_{$event} ON {$table}");
            }
        }
        DB::statement('DROP FUNCTION mailbox_changed()');
    }
};
