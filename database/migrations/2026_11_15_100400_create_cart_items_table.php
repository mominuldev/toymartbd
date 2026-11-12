<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.4 `cart_items` (P4-CRT-01). One line per variant per cart — the
 * unique key is what makes adding twice, and merging a guest cart, add quantity
 * rather than duplicate a line. `unit_price` is the price when added (shown as
 * "price changed" when it differs); totals are always re-priced from current data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamp('added_at');
            $table->timestamps();

            $table->unique(['cart_id', 'variant_id']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
