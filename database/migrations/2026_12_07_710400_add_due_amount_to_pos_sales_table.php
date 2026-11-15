<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS due / baki (owner decision 2026-11-15): the part of a counter sale the customer takes on
 * credit. No payment row is written for it; the order's `due_total` carries it. This column
 * is the counter's own record of how much was put on due at the charge (expand-only, default
 * 0.00, so every existing sale reads "fully paid").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->decimal('due_amount', 14, 2)->default('0.00')->after('change_due')->comment('Put on the customer\'s due: no payment row');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropColumn('due_amount');
        });
    }
};
