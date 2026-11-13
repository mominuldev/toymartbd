<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a stock adjustment line asked for (P5-INV): `requested_change` (a signed change)
 * or `counted` (a stock count's figure). A pending adjustment's quantity_before/after
 * are provisional; on approval they are re-read under the row lock, a counted line's
 * change is worked out again against the on_hand there then, and the line is
 * rewritten once with what actually moved. Null on rows from before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table): void {
            $table->integer('requested_change')->nullable()->after('variant_id');
            $table->integer('counted')->nullable()->after('requested_change');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table): void {
            $table->dropColumn(['requested_change', 'counted']);
        });
    }
};
