<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.7 `daily_financial_summaries` — the nightly rollup (01:00 Dhaka) that
 * dashboards and the P&L read instead of the ledger. One row per Dhaka date (rule 11),
 * rebuildable for any range (`toymart:finance:rollup --from= --to=`).
 *
 * "All the above aggregated" is, as built: one DECIMAL(14,2) column per ledger type, named
 * exactly as the type and stated in its natural direction (income types in − out, cost types
 * out − in, so reversals reduce them); the recognised (P&L) and cash totals; then the
 * operational counts §3.7 lists and `cod_receivable_closing` (receivables at day end).
 */
return new class extends Migration
{
    public const array LEDGER_TYPES = [
        'sale_revenue', 'cogs', 'discount_given', 'shipping_revenue', 'courier_charge', 'courier_cod_fee',
        'courier_return_charge', 'gateway_fee', 'packaging', 'marketing', 'salary', 'rent', 'utility', 'refund',
        'inventory_write_off', 'supplier_payment', 'cod_received', 'other_income', 'other_expense',
    ];

    public function up(): void
    {
        Schema::create('daily_financial_summaries', function (Blueprint $table): void {
            $table->date('summary_date')->primary()->comment('Dhaka calendar date');
            foreach (self::LEDGER_TYPES as $type) {
                $table->decimal($type, 14, 2)->default('0.00');
            }
            $table->decimal('recognised_in', 14, 2)->default('0.00');
            $table->decimal('recognised_out', 14, 2)->default('0.00');
            $table->decimal('net_profit', 14, 2)->default('0.00')->comment('recognised_in − recognised_out');
            $table->decimal('cash_in', 14, 2)->default('0.00');
            $table->decimal('cash_out', 14, 2)->default('0.00');
            $table->decimal('net_cash', 14, 2)->default('0.00');
            $table->unsignedInteger('orders_placed')->default(0);
            $table->unsignedInteger('orders_delivered')->default(0);
            $table->unsignedInteger('orders_cancelled')->default(0);
            $table->unsignedInteger('orders_returned')->default(0);
            $table->unsignedInteger('new_customers')->default(0);
            $table->unsignedInteger('units_sold')->default(0);
            $table->decimal('cod_receivable_closing', 14, 2)->default('0.00');
            $table->timestamp('computed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_financial_summaries');
    }
};
