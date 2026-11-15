<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider said AFTER it accepted a message (WhatsApp Cloud API `statuses` webhooks,
 * docs/api/inbox.md): sent → delivered → read, or failed. Separate from `status`, which records
 * our send attempt (queued / sent / failed / dead_lettered / skipped) and is what the spend and
 * dead-letter screens read. Additive, nullable columns only (expand step, no backfill) — safe on
 * a populated table. Indexed by provider message id: that is how a webhook finds its row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->string('delivery_status', 16)->nullable()->after('failed_at');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->string('delivery_error', 300)->nullable()->after('read_at');
            $table->index(['channel', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropIndex(['channel', 'provider_message_id']);
            $table->dropColumn(['delivery_status', 'delivered_at', 'read_at', 'delivery_error']);
        });
    }
};
