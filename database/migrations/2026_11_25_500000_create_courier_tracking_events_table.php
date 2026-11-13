<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `courier_tracking_events` (P6 status ingestion): the shipment's
 * courier history, one row per status the shipment ENTERED. Neither provider offers a
 * tracking-history endpoint usable for every parcel (Pathao: snapshot only), so the history
 * is accumulated here from every applied status move — webhook-triggered re-query, the
 * reconciliation poll, or a manual step. It is what the customer's tracking timeline shows
 * for the milestones `shipments` has no column for (in transit, out for delivery, on hold).
 *
 * Append-only by convention (the model refuses updates and deletes). UNIQUE
 * (shipment_id, normalised_status, occurred_at) makes a re-applied move a no-op. FKs: shipments
 * (2026_11_25_100100) and courier_providers (earlier) both exist before this file.
 * `payload` never holds a recipient's phone, name or address (rule 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('courier_providers')->restrictOnDelete();
            $table->string('normalised_status', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->string('message', 255)->nullable();
            $table->string('location', 191)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->json('payload')->nullable();
            $table->string('source', 16)->comment('webhook|poll|manual|booking');
            $table->timestamp('created_at')->nullable();

            $table->unique(['shipment_id', 'normalised_status', 'occurred_at'], 'courier_tracking_events_idempotent');
            $table->index(['shipment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_tracking_events');
    }
};
