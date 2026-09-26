<?php

use App\Organization\SystemFolders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('system_role')->nullable();
            $table->integer('position');
            $table->string('color', 7)->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'system_role']);
            $table->unique(['id', 'user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX folders_user_name_unique ON folders (user_id, lower(name))');
        DB::statement("ALTER TABLE folders ADD CONSTRAINT folders_system_role_check CHECK (system_role IN ('inbox', 'sent', 'archive') OR system_role IS NULL)");

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable();
        });
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_folder_owner_fk FOREIGN KEY (folder_id, user_id) REFERENCES folders (id, user_id)');

        $now = now();
        foreach ([['inbox', 'Inbox', 0], ['sent', 'Sent', 1], ['archive', 'Archive', 2]] as [$role, $name, $position]) {
            DB::table('folders')->insertUsing(
                ['user_id', 'name', 'system_role', 'position', 'created_at', 'updated_at'],
                DB::table('users')->selectRaw('id, ? AS name, ? AS system_role, ? AS position, ? AS created_at, ? AS updated_at', [$name, $role, $position, $now, $now]),
            );
        }
        SystemFolders::backfillMissing();

        DB::statement("CREATE INDEX messages_inbox_feed_idx ON messages (folder_id, sort_date DESC, id DESC) WHERE deleted_at IS NULL AND is_done = false AND remote_status <> 'removed'");
        DB::statement("CREATE INDEX messages_unread_feed_idx ON messages (user_id, sort_date DESC, id DESC) WHERE deleted_at IS NULL AND is_read = false AND remote_status <> 'removed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_inbox_feed_idx');
        DB::statement('DROP INDEX IF EXISTS messages_unread_feed_idx');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign('messages_folder_owner_fk');
            $table->dropColumn('folder_id');
        });
        Schema::dropIfExists('folders');
    }
};
