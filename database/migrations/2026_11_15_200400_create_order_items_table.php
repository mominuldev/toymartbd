<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.4 `order_items` (P4-ORD-01). Everything that can change is
 * snapshotted: name (EN + BN), variant name, SKU, image, attributes, price — and
 * `cost_price_snapshot`, written once at order creation from the variant's weighted
 * average cost and never recalculated (rule 7; the model refuses to change it).
 *
 * `cost_price_snapshot` is DECIMAL(14,4) as §3.4 specifies (it carries the weighted
 * average cost's precision, `stock_levels.avg_cost` is 14,4); every selling-side money
 * column is DECIMAL(14,2) (rule 6).
 *
 * `recommendation_source` is the winning placement stamped at checkout (rule 25d).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('product_name', 255);
            $table->string('product_name_bn', 255)->nullable();
            $table->string('variant_name', 160)->nullable();
            $table->string('sku', 64);
            $table->string('image_path', 500)->nullable();
            $table->json('variant_attributes')->nullable()->comment('§3.4 `attributes`: renamed, it shadows Eloquent\'s $attributes');

            $table->decimal('unit_price', 14, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('discount', 14, 2)->default('0.00');
            $table->decimal('tax', 14, 2)->default('0.00');
            $table->decimal('line_total', 14, 2);
            $table->decimal('cost_price_snapshot', 14, 4);

            $table->unsignedInteger('quantity_shipped')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->unsignedInteger('quantity_refunded')->default(0);
            $table->string('recommendation_source', 32)->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['variant_id', 'created_at']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
