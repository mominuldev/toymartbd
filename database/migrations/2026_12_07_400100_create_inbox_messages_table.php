<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages of an inbox conversation (P10, Figma B · 22). `kind` = text (a real channel
 * message), note (internal, never sent) or system (assigned / resolved / reopened line, never
 * sent). UNIQUE (channel, provider_message_id) makes inbound webhook replays a no-op: the
 * provider's message id (WhatsApp `wamid`, Messenger `mid`) is the idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('inbox_conversations')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('direction', 8)->comment('in|out');
            $table->string('kind', 8)->default('text')->comment('text|note|system');
            $table->text('body')->nullable();
            $table->string('system_event', 24)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_status', 16)->nullable()->comment('queued|sent|delivered|read|failed; outbound text only');
            $table->string('provider_message_id', 191)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 300)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('provider_sent_at')->nullable()->comment('inbound: the provider timestamp');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'provider_message_id']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
