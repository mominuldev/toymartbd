<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified inbox (P10, Figma B · 22) — DATABASE-DESIGN "Inbox". One row per customer thread on
 * one channel, keyed by the provider's own thread id (WhatsApp `wa_id`/BSUID, Messenger PSID).
 *
 * `contact_phone` is E.164 and only set when the channel gives a phone (WhatsApp); it is shown
 * masked without customers.view_pii (rule 20). `last_inbound_at` drives Meta's 24-hour
 * customer-service window. `needs_human` = the customer's latest message still awaits a staff
 * reply (no AI provider is chosen; see docs/api/inbox.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16)->comment('whatsapp|messenger|live_chat');
            $table->string('external_thread_id', 191);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 20)->nullable()->comment('E.164; masked without customers.view_pii');
            $table->string('status', 16)->default('open')->comment('open|resolved');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('needs_human')->default(false);
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_direction', 8)->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_thread_id']);
            $table->index(['status', 'last_message_at']);
            $table->index(['assigned_to', 'status']);
            $table->index(['needs_human', 'status']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_conversations');
    }
};
