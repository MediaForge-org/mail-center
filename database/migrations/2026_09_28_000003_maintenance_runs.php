<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_runs', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->jsonb('scope')->default('{}');
            $t->unsignedInteger('target_version');
            $t->unsignedBigInteger('cursor')->default(0);
            $t->string('status')->default('pending');
            $t->uuid('lease_token')->nullable();
            $t->timestampTz('lease_expires_at')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->jsonb('stats')->default('{"processed":0,"failed":0}');
            $t->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX maintenance_active ON maintenance_runs(kind, scope, target_version) WHERE status IN ('pending','processing')");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
    }
};
