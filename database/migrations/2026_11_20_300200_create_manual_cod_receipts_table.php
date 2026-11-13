<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual COD receipts (P5 finance; an addition to DATABASE-DESIGN §3.7). Until courier
 * settlement reconciliation (Phase 6, §4.5) exists, staff record cash received from the
 * courier against a delivered COD order here. Each row writes one `cod_received` ledger
 * entry (cash, not revenue) with source `manual_cod_receipt`. Insert-only, like the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_cod_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('received_on')->comment('Dhaka calendar date');
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_cod_receipts');
    }
};
