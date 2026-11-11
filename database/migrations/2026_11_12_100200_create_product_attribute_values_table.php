<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `product_attribute_values` (P4-CAT-09): `id · attribute_id
 * FK · value · value_bn · slug · color_hex NULL · position`. A value slug is unique
 * within its attribute only ("red" can exist under Color and under Theme).
 *
 * Deleting an attribute cascades to its values at the DB level, but the Actions
 * refuse to delete a value (or an attribute) that a variant still references via
 * `variant_attribute_values.attribute_value_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->string('value', 80);
            $table->string('value_bn', 80)->nullable();
            $table->string('slug', 64);
            $table->char('color_hex', 7)->nullable()->comment('#RRGGBB, colour-type attributes only');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'slug']);
            $table->index(['attribute_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
