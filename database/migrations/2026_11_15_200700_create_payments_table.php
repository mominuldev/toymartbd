<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.5 `payments` (P4-PAY-01). One row per payment attempt; COD is a
 * first-class method (rule 33) moving through the same PaymentStatus transitions as an
 * online gateway. `gateway_payload` keeps the provider's raw answer verbatim (§1.7).
 * `fee_amount` is recorded per payment so gateway charges are real numbers in the P&L.
 *
 * `transaction_id` is UNIQUE: MySQL and SQLite both allow many NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('method', 24)->comment('PaymentMethod');
            $table->string('gateway', 32)->nullable()->comment('driver code');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('status', 16)->default('pending')->comment('PaymentStatus');
            $table->string('transaction_id', 128)->nullable()->unique();
            $table->string('gateway_reference', 191)->nullable();
            $table->json('gateway_payload')->nullable();
            $table->decimal('fee_amount', 14, 2)->default('0.00');
            $table->decimal('net_amount', 14, 2)->default('0.00');
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
