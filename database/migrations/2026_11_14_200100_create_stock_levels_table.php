<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `stock_levels` (P4-CRT-05) — the current-state projection of
 * `stock_movements`. Invariant (§7.1): SUM(stock_movements.quantity) = on_hand per
 * (variant, location). `available = on_hand - reserved` is derived, never stored.
 *
 * Written only by StockLedger / StockReservations under SELECT … FOR UPDATE in
 * ascending variant_id order (rule 9). Signed INTs: a movement type that allows a
 * negative balance (none at MVP) must be able to record it truthfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('incoming')->default(0);
            $table->integer('reorder_point')->default(0);
            $table->integer('reorder_quantity')->default(0);
            $table->decimal('avg_cost', 14, 4)->default('0.0000');
            $table->timestamps();

            $table->unique(['variant_id', 'location_id']);
            $table->index('on_hand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
