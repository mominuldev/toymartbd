<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN `tracking_events` (PRD K-01..K-03, SYSTEM-ARCHITECTURE §8 "Server-side
 * tracking"): every commerce event we relay server-side, with its dispatch result per
 * destination, so the admin diagnostics screen can say WHY a conversion is missing.
 *
 * Append-only: the only columns ever updated are the dispatch-status ones (`*_status`,
 * `*_response`, `*_attempts`, `dispatched_at`, `payload.sent`). Never deleted, never
 * soft-deleted (§ data rules). `payload` is the event AS SENT, user data already SHA-256
 * hashed and the client IP / user agent redacted — never raw PII (rule 20).
 *
 * UNIQUE (event_id, event_name, source) is the idempotent-ingest key: the same browser
 * event posted twice, or a purchase recorded by both a retry and the original, is one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->string('event_name', 40);
            $table->string('source', 16)->comment('browser|server');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->decimal('value', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('payload')->nullable();
            $table->json('consent_state')->nullable();
            $table->string('ga4_status', 24)->default('pending');
            $table->json('ga4_response')->nullable();
            $table->unsignedTinyInteger('ga4_attempts')->default(0);
            $table->string('meta_status', 24)->default('pending');
            $table->json('meta_response')->nullable();
            $table->unsignedTinyInteger('meta_attempts')->default(0);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'event_name', 'source'], 'tracking_events_idempotent');
            $table->index('event_id');
            $table->index(['event_name', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
