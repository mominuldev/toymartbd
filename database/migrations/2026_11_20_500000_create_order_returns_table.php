<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns (RMA) — the goods side of DATABASE-DESIGN §4.7 (PRD O-21, admin checklist §2.2
 * "RMA list, reason, inspection result, restock"). The money side stays in `refunds`; an
 * inspected return points at the refund it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 24)->unique()->comment('RMA-YY-NNNNN');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('status', 16)->default('requested')->comment('ReturnStatus');
            $table->string('reason', 24)->comment('ReturnReason');
            $table->string('reason_note', 500)->nullable();
            $table->boolean('refund_shipping')->default(false);
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_returns');
    }
};
