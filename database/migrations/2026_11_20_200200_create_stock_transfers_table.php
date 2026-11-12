<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `stock_transfers` (P5-INV, PRD I-14) — the source document of a move between two
 * stock locations. Its units move as one `transfer_out` at the source and one
 * `transfer_in` at the destination per line (`reference_type = 'stock_transfer'`), in
 * one transaction. `number` (TRF-YYYY-NNNN) is what the movement ledger shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 16)->default('completed')->comment('StockTransferStatus');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['from_location_id', 'created_at']);
            $table->index(['to_location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
