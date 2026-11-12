<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.5 `refunds` (P4-PAY-01). The refund flow itself
 * (§4.7, approval threshold, audit_sensitive) is Phase 5; this batch provides the schema
 * and the gateway contract's refund entry point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 500);
            $table->string('type', 8)->comment('full|partial');
            $table->string('method', 24)->comment('PaymentMethod');
            $table->string('status', 16)->default('requested')->comment('RefundStatus');
            $table->string('gateway_reference', 191)->nullable();
            $table->boolean('restock')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
