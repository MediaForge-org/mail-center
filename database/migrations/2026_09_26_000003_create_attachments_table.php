<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->char('blob_sha256', 64);
            $table->foreign('blob_sha256')->references('sha256')->on('blobs')->restrictOnDelete();
            $table->string('filename', 255);
            $table->string('content_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disposition', 10);
            $table->string('content_id', 998)->nullable();
            $table->string('mime_part_path', 100);
            $table->unsignedInteger('part_order');
            $table->timestampTz('created_at');
            $table->unique(['message_id', 'mime_part_path']);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('attachments_extracted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('attachments_extracted_at'));
    }
};
