<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_share_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('envelope_version', 16);
            $table->string('message_id', 64);
            $table->uuid('correlation_id');
            $table->string('intent', 128);
            $table->string('intent_version', 16);
            $table->uuid('source_service_id');
            $table->uuid('target_service_id');
            $table->uuid('user_id');
            $table->string('idempotency_key', 191);
            $table->timestamp('issued_at');
            $table->json('payload');
            $table->string('signature_header', 191);
            $table->json('headers')->nullable();
            $table->timestamp('received_at');
            $table->string('processing_status', 32)->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->unique(['source_service_id', 'idempotency_key'], 'inbound_share_msgs_src_idemp_unique');
            $table->index('correlation_id');
            $table->index('processing_status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_share_messages');
    }
};
