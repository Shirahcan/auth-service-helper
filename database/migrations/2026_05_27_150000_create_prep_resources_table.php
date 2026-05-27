<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prep_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_service_id');
            $table->string('idempotency_key', 255);
            $table->string('intent', 128);
            $table->string('intent_version', 16)->default('1.0');
            $table->json('source_resource');
            $table->json('student_data');
            $table->json('payload')->nullable();
            $table->json('signed_data')->nullable();
            $table->string('return_to', 2048)->nullable();
            $table->string('status', 32)->default('prepared');
            $table->timestamp('prepared_at');
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('expires_at');
            $table->uuid('permanent_resource_id')->nullable();
            $table->timestamps();

            $table->unique(['source_service_id', 'idempotency_key'], 'prep_resources_src_idemp_unique');
            $table->index(['status', 'expires_at']);
            $table->index(['intent', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prep_resources');
    }
};
