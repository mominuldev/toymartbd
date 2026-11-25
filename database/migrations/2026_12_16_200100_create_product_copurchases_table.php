<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `product_copurchases` (P10, PRD X-04 frequently bought together): a rebuildable projection of
 * how many distinct non-cancelled orders in the last 180 days contained both products. Both
 * directions are stored, so a read is one indexed range on `product_id`. Rebuilt nightly by
 * RebuildProductCopurchases (`toymart:catalog:copurchases`); never edited by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_copurchases', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('other_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('orders_count');
            $table->dateTime('last_ordered_at');
            $table->dateTime('computed_at');

            $table->primary(['product_id', 'other_product_id']);
            $table->index(['product_id', 'orders_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_copurchases');
    }
};
