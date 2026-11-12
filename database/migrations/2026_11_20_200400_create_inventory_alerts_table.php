<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `inventory_alerts` (P5-INV; DATABASE-DESIGN §7 "writes any drift to an alert table").
 * One row per (kind, variant, location) episode: a low/out-of-stock level, or a drift
 * the nightly reconciliation found between the ledger and the levels. `expected` is what
 * the source of truth says (the ledger sum, the open-order quantity), `actual` what
 * stock_levels holds; for a stock alert `actual` is the available quantity and
 * `expected` the reorder point. `alert_date` is the Dhaka calendar day it was raised
 * (rule 11) — what the daily digest buckets by. Never auto-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24)->comment('InventoryAlertKind');
            $table->string('status', 16)->default('open')->comment('InventoryAlertStatus');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->integer('expected')->nullable();
            $table->integer('actual')->nullable();
            $table->date('alert_date');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
            $table->index(['variant_id', 'location_id', 'kind', 'status']);
            $table->index(['alert_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
