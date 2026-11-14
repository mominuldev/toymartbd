<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly rollup keeps one column per ledger type (DailySummaryRollup). POS tenders are
 * booked as `pos_receipt` — cash, never revenue (rule 10) — so the column exists before the
 * first such entry. Expand-only: a defaulted column, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_financial_summaries') && ! Schema::hasColumn('daily_financial_summaries', 'pos_receipt')) {
            Schema::table('daily_financial_summaries', function (Blueprint $table): void {
                $table->decimal('pos_receipt', 14, 2)->default('0.00');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('daily_financial_summaries', 'pos_receipt')) {
            Schema::table('daily_financial_summaries', function (Blueprint $table): void {
                $table->dropColumn('pos_receipt');
            });
        }
    }
};
