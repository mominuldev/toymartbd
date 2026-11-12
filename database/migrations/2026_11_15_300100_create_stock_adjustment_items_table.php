<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `stock_adjustment_items` — one line per variant of an
 * adjustment: on_hand before and after (read under the row lock), and the unit cost the
 * units moved at. Append-only in practice: an adjustment is never edited; a mistake is
 * corrected by a new adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['adjustment_id', 'variant_id']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
