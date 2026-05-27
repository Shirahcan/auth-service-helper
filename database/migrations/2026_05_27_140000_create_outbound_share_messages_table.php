<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbound_share_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('share_id');
            $table->uuid('target_service_id');
            $table->string('peer_slug', 100);

            $table->string('intent', 150);
            $table->string('intent_version', 20);
            $table->string('idempotency_key', 255);

            $table->json('envelope_json');
            $table->string('signature_header', 255)->nullable();

            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();

            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index('share_id');
            $table->index(['peer_slug', 'status']);
            $table->unique(['peer_slug', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_share_messages');
    }
};
