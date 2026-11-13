<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a receipt's landed costs were paid to someone other than the supplier
 * (`costs_paid_to_supplier = false`), who was paid, the reference and — if already paid —
 * how. Finance turns them into a capitalised expense (cash out, never a P&L cost).
 * Expand-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->string('costs_vendor', 160)->nullable()->after('costs_paid_to_supplier');
            $table->string('costs_reference', 120)->nullable()->after('costs_vendor');
            $table->string('costs_payment_method', 24)->nullable()->after('costs_reference')->comment('ExpensePaymentMethod; null = not yet paid');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropColumn(['costs_vendor', 'costs_reference', 'costs_payment_method']);
        });
    }
};
