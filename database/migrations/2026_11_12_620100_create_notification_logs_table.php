<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.8 `notification_logs` (P4-NOT-01): one row per attempted message,
 * with its per-message cost so SMS/WhatsApp spend lands in the P&L as a real figure.
 *
 * `recipient` is MASKED (rule 20) — the full number is resolved from the notifiable at
 * send time and never stored here. `recipient_hash` (SHA-256 of the E.164 number or the
 * lower-cased email) lets support find every message to one customer without storing it.
 * `notifiable_*` carry no FK: orders/customers are created by later migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('template', 64);
            $table->string('category', 32);
            $table->string('locale', 5);
            $table->string('recipient', 64)->nullable();
            $table->char('recipient_hash', 64)->nullable();
            $table->string('notifiable_type', 64)->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16);
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->unsignedSmallInteger('segments')->nullable();
            $table->string('encoding', 8)->nullable();
            $table->decimal('cost', 14, 2)->default('0.00');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('fallback_of_id')->nullable()->constrained('notification_logs')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['channel', 'id']);
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('recipient_hash');
            $table->index(['template', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
