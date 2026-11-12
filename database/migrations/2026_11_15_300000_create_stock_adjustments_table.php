<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `stock_adjustments` — the source document behind every manual
 * stock change (damage, loss, found, recount …). Its lines are `stock_adjustment_items`;
 * the stock itself moves through `stock_movements` with `reference_type =
 * 'stock_adjustment'`. `number` (ADJ-YYYY-NNNN) is what the movement ledger shows as
 * the reference. Written only by AdjustStock. `approved_by` stays null until the
 * approval step (Phase 5) exists; `status` is `applied` at MVP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('reason', 32)->comment('StockAdjustmentReason');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('applied')->comment('StockAdjustmentStatus');
            $table->timestamps();

            $table->index(['location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
