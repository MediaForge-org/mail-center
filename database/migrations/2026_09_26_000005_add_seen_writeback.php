<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->boolean('write_back_seen')->default(false);
            $table->unsignedBigInteger('seen_mirror_generation')->default(0);
            $table->string('seen_writeback_error')->nullable();
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('read_intent_generation')->default(0);
            $table->unsignedBigInteger('seen_mirror_generation')->default(0);
            $table->boolean('seen_reconciled_remote')->nullable();
            $table->timestampTz('seen_reconciled_at', 6)->nullable();
        });
        Schema::table('message_locations', function (Blueprint $table) {
            $table->unsignedBigInteger('seen_observed_mirror_generation')->nullable();
            $table->unsignedBigInteger('seen_observed_read_generation')->nullable();
            $table->timestampTz('seen_observed_at', 6)->nullable();
        });
        Schema::create('user_change_versions', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(0);
        });
        Schema::create('remote_flag_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->string('flag')->default('\\Seen');
            $table->boolean('desired');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('mirror_generation');
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->jsonb('completed_location_ids')->default('[]');
            $table->index(['mail_account_id', 'status', 'next_attempt_at']);
            $table->unique(['message_id', 'flag', 'generation']);
        });
        DB::statement("CREATE UNIQUE INDEX remote_flags_one_pending ON remote_flag_changes (message_id, flag) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_flag_changes');
        Schema::dropIfExists('user_change_versions');
        Schema::table('message_locations', fn (Blueprint $t) => $t->dropColumn(['seen_observed_mirror_generation', 'seen_observed_read_generation', 'seen_observed_at']));
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn(['read_intent_generation', 'seen_mirror_generation', 'seen_reconciled_remote', 'seen_reconciled_at']));
        Schema::table('mail_accounts', fn (Blueprint $t) => $t->dropColumn(['write_back_seen', 'seen_mirror_generation', 'seen_writeback_error']));
    }
};
