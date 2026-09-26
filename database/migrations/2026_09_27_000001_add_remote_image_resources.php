<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_bodies', function (Blueprint $table) {
            $table->jsonb('remote_resources')->default('{}');
        });
        Schema::create('remote_content_allowlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('address', 254);
            $table->timestampsTz();
            $table->unique(['user_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_content_allowlist');
        Schema::table('message_bodies', fn (Blueprint $table) => $table->dropColumn('remote_resources'));
    }
};
