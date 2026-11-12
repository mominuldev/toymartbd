<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A capitalised expense category (owner decision 2026-11-13): money spent on stock that
 * is already inside its landed cost — freight, customs, clearing paid to a third party.
 * Its expenses are cash-out only (`is_recognised_revenue = 0`, ledger type
 * `supplier_payment`): the cost reaches the P&L as COGS when the goods sell, so posting it
 * as an expense too would count it twice. Expand-only, default false.
 *
 * Dated 2026-11-21 so it runs after `expense_categories` is created (2026_11_20_300000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->boolean('capitalised')->default(false)->after('is_fixed');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->dropColumn('capitalised');
        });
    }
};
