<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 performance (docs/audits/PERFORMANCE.md P-07). The P&L statement, sales analytics and the
 * dashboard sum the ledger over a Dhaka date range, split by the revenue/cash flags, type and
 * direction. The existing (occurred_on, type) index finds the rows but every one then costs a
 * clustered-index lookup for the flags and the amount — for a year's range MySQL abandons the index
 * for a full scan. With these columns in the index the aggregation is answered from the index alone.
 *
 * financial_transactions is append-only (rule 8); MySQL 8 builds a secondary index online
 * (ALGORITHM=INPLACE, LOCK=NONE), so writers are not blocked while it builds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->index(['occurred_on', 'is_recognised_revenue', 'is_cash_movement', 'type', 'direction', 'amount'], 'ft_report_cover_idx');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropIndex('ft_report_cover_idx');
        });
    }
};
