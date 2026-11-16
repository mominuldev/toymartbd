<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly rollup keeps one column per ledger type (DailySummaryRollup). Owner capital is
 * booked as `owner_capital_in` / `owner_drawing` — cash, never revenue (rule 10) — so both
 * columns exist before the first such entry. Expand-only: defaulted columns, no backfill.
 */
return new class extends Migration
{
    private const array COLUMNS = ['owner_capital_in', 'owner_drawing'];

    public function up(): void
    {
        if (! Schema::hasTable('daily_financial_summaries')) {
            return;
        }
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('daily_financial_summaries', $column)) {
                Schema::table('daily_financial_summaries', function (Blueprint $table) use ($column): void {
                    $table->decimal($column, 14, 2)->default('0.00');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('daily_financial_summaries', $column)) {
                Schema::table('daily_financial_summaries', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
