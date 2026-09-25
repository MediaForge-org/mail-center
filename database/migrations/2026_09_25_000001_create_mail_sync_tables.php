<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider')->default('imap');
            $table->string('display_name');
            $table->string('email_address');
            $table->jsonb('aliases')->default('[]');
            $table->string('color', 7)->default('#5865a8');
            $table->string('short_label', 3);
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('incoming');
            $table->boolean('enabled')->default(true);
            $table->boolean('sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_seconds')->default(180);
            $table->date('sync_since')->nullable();
            $table->string('sync_status')->default('never_synced');
            $table->timestampTz('next_sync_at')->nullable();
            $table->jsonb('sync_state')->default('{"version":1}');
            $table->timestampTz('last_sync_started_at')->nullable();
            $table->timestampTz('last_sync_finished_at')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->jsonb('capabilities')->default('[]');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'position']);
            $table->index(['sync_enabled', 'next_sync_at']);
            $table->unique(['id', 'user_id']);
        });
        DB::statement('ALTER TABLE mail_accounts ALTER COLUMN email_address TYPE citext');

        Schema::create('mail_account_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->string('purpose')->default('incoming');
            $table->string('kind')->default('password');
            $table->text('ciphertext');
            $table->string('key_id');
            $table->timestampsTz();
            $table->unique(['mail_account_id', 'purpose']);
        });

        Schema::create('remote_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->text('raw_name');
            $table->text('name');
            $table->string('delimiter')->nullable();
            $table->string('role')->default('other');
            $table->jsonb('attributes')->default('[]');
            $table->boolean('selectable')->default(true);
            $table->boolean('sync_enabled')->default(true);
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('sync_high_uid')->default(0);
            $table->unsignedBigInteger('backfill_low_uid')->nullable();
            $table->timestampTz('backfill_completed_at')->nullable();
            $table->timestampTz('last_flag_scan_at')->nullable();
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->unsignedBigInteger('reconcile_cycle_id')->nullable();
            $table->unsignedInteger('last_exists')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mail_account_id', 'raw_name']);
            $table->unique(['id', 'mail_account_id']);
        });

        Schema::create('blobs', function (Blueprint $table) {
            $table->char('sha256', 64)->primary();
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk');
            $table->string('path');
            $table->string('encoding')->default('identity');
            $table->timestampTz('created_at');
        });

        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->text('subject_normalized')->default('');
            $table->timestampTz('first_message_at')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('dedupe_key', 72);
            $table->text('message_id_header')->nullable();
            $table->text('in_reply_to')->nullable();
            $table->jsonb('references')->default('[]');
            $table->text('subject')->default('');
            $table->text('from_name')->default('');
            $table->string('from_address')->default('');
            $table->jsonb('to')->default('[]');
            $table->jsonb('cc')->default('[]');
            $table->jsonb('bcc')->default('[]');
            $table->jsonb('reply_to')->default('[]');
            $table->timestampTz('date_header')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('sort_date');
            $table->string('direction')->default('inbound');
            $table->text('snippet')->default('');
            $table->unsignedInteger('size_bytes');
            $table->boolean('has_attachments')->default(false);
            $table->char('raw_blob_sha256', 64);
            $table->string('parse_status')->default('ok');
            $table->unsignedSmallInteger('parser_version')->default(1);
            $table->string('remote_status')->default('present');
            $table->timestampTz('remote_missing_since')->nullable();
            $table->timestampTz('remote_removed_at')->nullable();
            $table->boolean('remote_seen')->default(false);
            $table->boolean('remote_flagged')->default(false);
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_important')->default(false);
            $table->boolean('is_done')->default(false);
            $table->timestampTz('done_at')->nullable();
            $table->timestampTz('local_updated_at')->nullable();
            $table->unsignedBigInteger('local_revision')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['mail_account_id', 'dedupe_key']);
            $table->index(['user_id', 'sort_date', 'id']);
            $table->foreign('raw_blob_sha256')->references('sha256')->on('blobs')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE messages ALTER COLUMN from_address TYPE citext');

        Schema::create('message_bodies', function (Blueprint $table) {
            $table->foreignId('message_id')->primary()->constrained()->restrictOnDelete();
            $table->text('text_plain')->default('');
            $table->text('html_sanitized')->nullable();
            $table->unsignedSmallInteger('sanitizer_version')->default(0);
            $table->unsignedInteger('remote_content_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('message_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('remote_folder_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');
            $table->jsonb('flags')->default('[]');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('removed_at')->nullable();
            $table->string('removed_reason')->nullable();
            $table->unique(['remote_folder_id', 'uidvalidity', 'uid']);
            $table->index(['message_id', 'removed_at']);
        });
        DB::statement('ALTER TABLE message_locations ADD CONSTRAINT message_locations_folder_account_fk FOREIGN KEY (remote_folder_id, mail_account_id) REFERENCES remote_folders (id, mail_account_id)');

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->string('trigger');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('status')->default('running');
            $table->jsonb('stats')->default('{}');
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
        });

        Schema::create('sync_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remote_folder_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');
            $table->unsignedInteger('attempts')->default(1);
            $table->string('error_code');
            $table->string('last_error');
            $table->string('status')->default('retryable');
            $table->timestampTz('first_failed_at');
            $table->timestampTz('last_failed_at');
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unique(['remote_folder_id', 'uidvalidity', 'uid']);
        });

        Schema::create('connection_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('encrypted_settings')->nullable();
            $table->string('key_id');
            $table->string('status')->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('result_code')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('finished_at')->nullable();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('actor_type');
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('context')->default('{}');
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'connection_tests', 'sync_failures', 'sync_runs', 'message_locations', 'message_bodies', 'messages', 'threads', 'blobs', 'remote_folders', 'mail_account_credentials', 'mail_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
