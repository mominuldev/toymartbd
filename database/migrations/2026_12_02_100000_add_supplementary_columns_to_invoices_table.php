<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplementary invoices (P8 batch 3, owner decision 2026-11-13) — EXPAND step.
 *
 * An order edited upwards after its customer invoice was issued gets a supplementary
 * invoice for the increase (a reduction gets a credit note). So an order may now have more
 * than one `sales` invoice: exactly one `original` and any number of `supplementary` ones.
 *
 *  - `kind` — `original` (every existing row, and every purchase invoice) | `supplementary`.
 *  - `original_invoice_id` — the original a supplementary invoice adds to (NULL otherwise).
 *  - `source_type` / `source_id` — what caused a supplementary invoice (`order_edit`, the
 *    `order_events` row of the edit). UNIQUE, so a replayed edit never issues twice. MySQL
 *    allows any number of NULL pairs in a UNIQUE index, so originals are unaffected.
 *  - `order_event_id` — the order's last `order_events` id when the snapshot was taken (read
 *    under the order lock). An edit with a higher id came AFTER the invoice and needs a
 *    document; one with a lower or equal id is already in it. NULL for purchase invoices and
 *    for invoices issued before this column existed (they fall back to the timestamps).
 *  - `original_order_key` — a VIRTUAL generated column, `order_id` for an original and NULL
 *    for a supplementary invoice. MySQL has no partial indexes, so "one original per order"
 *    is UNIQUE (`type`, `original_order_key`): supplementary rows carry NULL and never
 *    collide. It replaces UNIQUE (`type`, `order_id`), which the CONTRACT migration
 *    (`2026_12_02_100100`) drops once this one is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('kind', 16)->default('original')->after('type')->comment('original | supplementary');
            $table->foreignId('original_invoice_id')->nullable()->after('kind')->constrained('invoices')->restrictOnDelete();
            $table->string('source_type', 32)->nullable()->after('original_invoice_id')->comment('order_edit (supplementary invoices)');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->unsignedBigInteger('order_event_id')->nullable()->after('order_id')->comment('last order_events id the snapshot reflects');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('original_order_key')->nullable()
                ->virtualAs("case when kind = 'original' then order_id end");
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['type', 'original_order_key'], 'invoices_type_original_order_unique');
            $table->unique(['source_type', 'source_id'], 'invoices_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_source_unique');
            $table->dropUnique('invoices_type_original_order_unique');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('original_order_key');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['original_invoice_id']);
            $table->dropColumn(['kind', 'original_invoice_id', 'source_type', 'source_id', 'order_event_id']);
        });
    }
};
