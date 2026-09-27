<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->bigInteger('sync_requested_generation')->default(0);
            $table->bigInteger('sync_started_generation')->default(0);
            $table->bigInteger('sync_completed_generation')->default(0);
            $table->timestampTz('sync_requested_at', 6)->nullable();
            $table->string('sync_request_trigger')->default('scheduled');
            $table->timestampTz('sync_dispatched_at', 6)->nullable();
        });
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->bigInteger('request_generation')->nullable();
            $table->timestampTz('requested_at', 6)->nullable();
            $table->timestampTz('worker_started_at', 6)->nullable();
            $table->jsonb('timings')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', fn (Blueprint $table) => $table->dropColumn(['request_generation', 'requested_at', 'worker_started_at', 'timings']));
        Schema::table('mail_accounts', fn (Blueprint $table) => $table->dropColumn(['sync_requested_generation', 'sync_started_generation', 'sync_completed_generation', 'sync_requested_at', 'sync_request_trigger', 'sync_dispatched_at']));
    }
};
