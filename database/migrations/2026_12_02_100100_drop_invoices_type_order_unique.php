<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplementary invoices (P8 batch 3) — CONTRACT step. UNIQUE (`type`, `order_id`) would
 * refuse an order's supplementary invoice; "one original per order" is now held by UNIQUE
 * (`type`, `original_order_key`) from `2026_12_02_100000`. A plain (`order_id`, `kind`)
 * index keeps "this order's invoices" an index lookup (and backs the order_id foreign key
 * on MySQL, which needs an index led by the column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index(['order_id', 'kind'], 'invoices_order_kind_idx');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_type_order_unique');
        });
        // Left behind by a rollback of this migration (down()); redundant with the one above.
        if (Schema::hasIndex('invoices', 'invoices_order_idx')) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropIndex('invoices_order_idx'));
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['type', 'order_id'], 'invoices_type_order_unique');
            // MySQL: the order_id foreign key needs an index led by order_id before the
            // (order_id, kind) one can go.
            $table->index(['order_id'], 'invoices_order_idx');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_order_kind_idx');
        });
    }
};
