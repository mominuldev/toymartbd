<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `variant_attribute_values` (P4-CAT-01): which value of each
 * variant-defining attribute a variant carries. PK `(variant_id, attribute_id)` —
 * one value per attribute per variant. The `attribute_value_id` index answers
 * "which variants use this value?" when a value is removed from a product.
 *
 * A pivot, so no timestamps (the §1 "every table has timestamps" convention is
 * applied to entity tables only, as with category_product).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_attribute_values', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('product_attributes')->restrictOnDelete();
            $table->foreignId('attribute_value_id')->constrained('product_attribute_values')->restrictOnDelete();

            $table->primary(['variant_id', 'attribute_id']);
            $table->index('attribute_value_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_values');
    }
};
