<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The running number behind in-store EAN-13 barcodes (GenerateVariantBarcodes): one row per
 * prefix (`config/catalog.php` → `barcode.prefix`), locked FOR UPDATE inside the Action's
 * transaction so two generators never hand out the same number. `next_number` is the next
 * candidate; numbers already typed into product_variants.barcode are skipped, never reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcode_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 3)->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_sequences');
    }
};
