<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `product_attributes` (P4-CAT-09): `id · name · name_bn ·
 * slug UNIQUE · type (select|color|text) · is_variant_defining · is_filterable ·
 * position`, plus timestamps per the table conventions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_bn', 80)->nullable();
            $table->string('slug', 64)->unique();
            $table->string('type', 16)->default('select');
            $table->boolean('is_variant_defining')->default(true);
            $table->boolean('is_filterable')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
