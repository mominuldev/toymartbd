<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.4 `order_financials` (P4-ORD-01) — one row per order, a projection
 * of the `financial_transactions` ledger (§7.2). Computed by the Finance module (Phase 5);
 * this batch creates the table only. Money is DECIMAL(14,2) (rule 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_financials', function (Blueprint $table): void {
            $table->foreignId('order_id')->primary()->constrained('orders')->restrictOnDelete();
            foreach ([
                'revenue', 'cogs', 'gross_profit', 'discount_cost', 'courier_charge', 'courier_cod_fee',
                'courier_return_charge', 'gateway_fee', 'packaging_cost', 'allocated_marketing_cost',
                'refund_amount', 'return_loss', 'net_profit',
            ] as $column) {
                $table->decimal($column, 14, 2)->default('0.00');
            }
            $table->decimal('margin_percent', 7, 2)->nullable();
            $table->timestamp('computed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_financials');
    }
};
