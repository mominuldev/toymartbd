<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS due collections (owner decision 2026-11-15): money a customer brings to the counter
 * against their open due, taken on an open drawer. One row per collection; the money itself
 * is the payments it wrote (pos_due_collection_allocations → payments). `collected` is what
 * was applied to orders; `change_due` came out of cash; `remaining_due` is the customer's
 * open due left after it. Written once.
 *
 * UNIQUE (session_id, idempotency_key) backs the Idempotency-Key middleware, exactly as on
 * pos_sales: a retry after the cache record expired still finds the first collection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_due_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('collected', 14, 2)->comment('Σ applied to orders');
            $table->decimal('tendered_total', 14, 2)->comment('Σ tenders handed over, cash before change');
            $table->decimal('cash_tendered', 14, 2)->default('0.00');
            $table->decimal('change_due', 14, 2)->default('0.00');
            $table->decimal('remaining_due', 14, 2)->default('0.00')->comment('Customer open due after this collection');
            $table->timestamps();

            $table->unique(['session_id', 'idempotency_key']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_due_collections');
    }
};
