<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signature-verified inbox webhook payloads (WhatsApp Cloud API, Messenger), kept 90 days for
 * disputes and processed by a queued job (routes/api_v1_webhooks.php rules 3–5). Only written
 * AFTER the X-Hub-Signature-256 check passes (rule 19). UNIQUE body hash: a re-delivery of the
 * same bytes is a no-op; message-level idempotency is on inbox_messages.provider_message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('body_sha256', 64);
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('events_count')->default(0);
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->unique(['channel', 'body_sha256']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_webhook_receipts');
    }
};
