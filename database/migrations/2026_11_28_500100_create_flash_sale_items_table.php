<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flash-sale lines (P7): one variant at a sale price for a limited allotment inside a
 * `flash_sale` promotion's hard window. `claimed` counts units on open orders and is
 * changed only by PromotionUsages under `SELECT … FOR UPDATE` on these rows (ascending
 * variant id, rule 9), inside the order transaction — so the allotment cannot be
 * oversold. A cancelled order gives its units back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('sale_price', 14, 2);
            $table->unsignedInteger('allotment');
            $table->unsignedInteger('claimed')->default(0);
            $table->unsignedSmallInteger('per_order_limit')->nullable();
            $table->timestamps();

            $table->unique(['promotion_id', 'variant_id']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_items');
    }
};
