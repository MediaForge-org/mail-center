<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', fn (Blueprint $t) => $t->unique(['id', 'mail_account_id', 'user_id']));
        Schema::table('messages', function (Blueprint $t) {
            $t->foreign(['thread_id', 'mail_account_id', 'user_id'])->references(['id', 'mail_account_id', 'user_id'])->on('threads');
            $t->index(['thread_id', 'sort_date', 'id']);
            $t->index(['mail_account_id', 'message_id_header']);
            $t->index(['mail_account_id', 'in_reply_to']);
        });
        DB::statement('CREATE INDEX messages_references_gin ON messages USING gin ("references")');
        Schema::create('thread_repairs', function (Blueprint $t) {
            $t->foreignId('message_id')->primary()->constrained('messages')->cascadeOnDelete();
            $t->unsignedBigInteger('after_id')->default(0);
            $t->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_repairs');
        DB::statement('DROP INDEX messages_references_gin');
        Schema::table('messages', function (Blueprint $t) {
            $t->dropForeign(['thread_id', 'mail_account_id', 'user_id']);
            $t->dropIndex(['thread_id', 'sort_date', 'id']);
            $t->dropIndex(['mail_account_id', 'message_id_header']);
            $t->dropIndex(['mail_account_id', 'in_reply_to']);
        });
        Schema::table('threads', fn (Blueprint $t) => $t->dropUnique(['id', 'mail_account_id', 'user_id']));
    }
};
