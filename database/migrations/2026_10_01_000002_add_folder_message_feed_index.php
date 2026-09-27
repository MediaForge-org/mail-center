<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same shape as the M3.8 account feed index: a folder's first page must not scan past unrelated
 * rows from other folders when ordering by (sort_date DESC, id DESC). Local folder browsing (M4.1)
 * introduces exactly that access pattern for messages.folder_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX messages_folder_feed_idx ON messages (folder_id, sort_date DESC, id DESC) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX messages_folder_feed_idx');
    }
};
