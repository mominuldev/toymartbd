<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS sales (Figma B · 33): one row per charged counter sale, linking the drawer session to
 * the order the sale created (`orders.source = pos`). The order carries the money, items,
 * cost snapshots and payments; this row carries what only the counter knows — the cash handed
 * over, the change given back and the Idempotency-Key of the Charge request.
 *
 * UNIQUE (session_id, idempotency_key) is the database-level guard behind the middleware's
 * cache: a retried Charge after the cache record expired still finds the first sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('grand_total', 14, 2);
            $table->decimal('tendered_total', 14, 2)->comment('Σ tenders handed over, cash before change');
            $table->decimal('cash_tendered', 14, 2)->default('0.00');
            $table->decimal('change_due', 14, 2)->default('0.00');
            $table->timestamps();

            $table->unique(['session_id', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};
