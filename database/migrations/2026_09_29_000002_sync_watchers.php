<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_watchers', function (Blueprint $table) {
            $table->foreignId('remote_folder_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->uuid('owner')->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->string('state')->default('polling');
            $table->string('fingerprint', 64);
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_watchers');
    }
};
