<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `product_variants` (P4-CAT-01) — the unit of inventory.
 * Money is DECIMAL(14,2) (rule 6). `cost_price` is the current replacement cost
 * for margin display only; historical cost lives on order_items (rule 7).
 *
 * A variant is archived (`status = archived`), never deleted, once it can carry
 * stock or order history: removing an attribute value from a product archives the
 * variants that used it (P4-CAT-03). The CASCADE on product_id exists only for a
 * hard delete of a never-sold product, which no use case performs today.
 *
 * `barcode` is UNIQUE NULL: MySQL and SQLite both allow many NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 32)->nullable()->unique();
            $table->string('name', 160)->nullable()->comment('e.g. "Red / Large"');
            $table->string('name_bn', 160)->nullable();
            $table->decimal('cost_price', 14, 2)->default('0.00');
            $table->decimal('price', 14, 2);
            $table->decimal('compare_at_price', 14, 2)->nullable();
            $table->decimal('wholesale_price', 14, 2)->nullable();
            $table->unsignedInteger('weight_grams')->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 16)->default('active')->comment('active|archived');
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
