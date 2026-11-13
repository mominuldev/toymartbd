<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `shipments` (P6 courier booking, §4.2). One row per attempt to send
 * an order with a courier; an order has at most one OPEN shipment (anything but `failed`
 * or `cancelled`) — enforced by RequestShipment under the order's row lock.
 *
 * Additions to §3.6: `courier_location_id` (the explicitly chosen/mapped
 * `courier_locations` row for providers that need one, rule 32 — no FK because that table
 * belongs to a parallel migration and a mapping row may be re-synced), `booking_attempts`
 * and `last_attempt_at` (retry queue), `failure_code` (machine-readable reason:
 * `unverified_provider`, `unmapped_location`, `provider_refused`, `transport`,
 * `completion_failed`…), `dispatched_at` (the order was marked shipped for this shipment —
 * stock consumed and `courier_charge` booked; null for a submitted bulk parcel not yet
 * confirmed), `cod_collected`, `requested_by`.
 *
 * `consignment_id` is a string (Steadfast returns an integer, Pathao a string; we never do
 * arithmetic on one). `tracking_code` is VARCHAR(32) and never length-validated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('courier_providers')->restrictOnDelete();
            $table->string('number', 20)->unique();
            $table->string('consignment_id', 64)->nullable();
            $table->string('tracking_code', 32)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('provider_status_raw', 64)->nullable();
            $table->unsignedBigInteger('courier_location_id')->nullable()->comment('courier_locations.id (rule 32), no FK');
            $table->unsignedInteger('weight_grams')->default(0);
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->decimal('cod_amount', 14, 2)->default('0.00');
            $table->decimal('declared_value', 14, 2)->default('0.00');
            $table->decimal('charge', 14, 2)->nullable();
            $table->decimal('cod_fee', 14, 2)->nullable();
            $table->decimal('return_charge', 14, 2)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->decimal('cod_collected', 14, 2)->nullable();
            $table->timestamp('pickup_requested_at')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedTinyInteger('delivery_attempts')->default(0);
            $table->unsignedSmallInteger('booking_attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('recipient_signature', 255)->nullable();
            $table->string('label_path', 255)->nullable();
            $table->json('booking_payload')->nullable()->comment('what was sent, PII-trimmed (rule 20)');
            $table->json('booking_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
            $table->index('consignment_id');
            $table->index(['provider_id', 'status']);
            $table->index(['status', 'booked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
