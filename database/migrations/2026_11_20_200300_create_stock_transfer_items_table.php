<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `stock_transfer_items` — one variant line of a transfer: the units moved and the unit
 * cost they carried (the source's weighted-average cost at that moment), which is what
 * the destination's weighted-average cost absorbs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['transfer_id', 'variant_id']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
