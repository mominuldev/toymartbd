<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `purchase_items` — one line per variant per PO. `unit_cost` is
 * the supplier's price (4 dp, like every unit cost); `discount` is the line's discount
 * in taka; `line_total` = quantity_ordered × unit_cost − discount. `landed_unit_cost`
 * is the quantity-weighted landed cost over every receipt of this line (null until the
 * first receipt). `quantity_received` never exceeds `quantity_ordered`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('landed_unit_cost', 14, 4)->nullable();
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['purchase_id', 'variant_id']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
