<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `purchase_receipt_items` (as built, P5): what one receipt brought in for one PO line.
 * `line_value` is the goods value of these units after the line discount;
 * `allocated_cost` is this line's share of the receipt's landed costs (Σ over a receipt
 * equals freight + customs_duty + other_cost to the poisha); `landed_unit_cost` =
 * (line_value + allocated_cost) / quantity. `avg_cost_before` / `avg_cost_after` record
 * the weighted-average step the receipt caused at its location (§5.3), for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('line_value', 14, 2);
            $table->decimal('allocated_cost', 14, 2);
            $table->decimal('landed_unit_cost', 14, 4);
            $table->decimal('avg_cost_before', 14, 4);
            $table->decimal('avg_cost_after', 14, 4);
            $table->timestamps();

            $table->index('purchase_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
    }
};
