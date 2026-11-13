<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `courier_settlement_items`: one statement line (or one MISSING line we
 * expected but the courier did not pay). `cod_amount`, `charges`, `cod_fee`, `return_charge`,
 * `net` are what the COURIER reported; `expected_cod` / `expected_net` are what our shipment
 * says; `variance` = net − expected_net. `variance_type` names the problem (none, short, over,
 * missing, unknown_consignment, not_delivered, already_settled, duplicate_line) and a person
 * resolves each one (`review_status` accepted|excluded) — never adjusted automatically.
 * `booked_at` marks the line's ledger entries written (idempotent per line).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained('courier_settlements')->restrictOnDelete();
            $table->unsignedInteger('line_no')->nullable()->comment('Row in the statement; null for a missing line');
            $table->string('consignment_id', 64)->nullable();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->decimal('cod_amount', 14, 2)->default('0.00');
            $table->decimal('charges', 14, 2)->default('0.00');
            $table->decimal('cod_fee', 14, 2)->default('0.00');
            $table->decimal('return_charge', 14, 2)->default('0.00');
            $table->decimal('net', 14, 2)->default('0.00');
            $table->decimal('expected_cod', 14, 2)->nullable();
            $table->decimal('expected_net', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->default('0.00');
            $table->string('variance_type', 24)->default('none');
            $table->boolean('is_matched')->default(false);
            $table->string('review_status', 12)->default('pending')->comment('pending|accepted|excluded');
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();
            $table->index(['settlement_id', 'variance_type']);
            $table->index(['shipment_id']);
            $table->index(['consignment_id']);
            $table->index(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_settlement_items');
    }
};
