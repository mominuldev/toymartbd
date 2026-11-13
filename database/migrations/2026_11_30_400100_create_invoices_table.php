<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.5 `invoices` (P8, PRD N-13). One immutable row per issued invoice:
 * `sales` (customer, one per order) or `purchase` (supplier, one per PO).
 *
 * Everything printed is snapshotted at issue — `issued_by` (company: name, address, trade
 * licence, BIN, hotline, logo media id, from store settings), `issued_to` (customer or
 * supplier), `lines` and the totals — so a reprint shows the details current THEN, never
 * today's (§3.5 note). Nothing is recomputed from the order or the PO afterwards.
 *
 * Additions to §3.5: `series`/`year`/`sequence` (the gap-free counter value, UNIQUE, so a
 * duplicate is impossible even if the lock were bypassed), `reference` (order or PO
 * number), `lines` JSON, `paid`/`due`, `payment_method`, `currency`, `amount_in_words`
 * (English, lakh/crore), timestamps. `template` = `standard` today; a VAT/Mushak layout
 * (N-14, P2, needs an accountant) would be a new template value, not a new table.
 * `pdf_path` stays null: the PDF is re-rendered from the snapshot, deterministically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16)->comment('sales | purchase');
            $table->string('series', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 32)->unique();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->string('reference', 32)->comment('order or PO number at issue');
            $table->json('issued_to');
            $table->json('issued_by');
            $table->json('lines');
            $table->string('currency', 3)->default('BDT');
            $table->decimal('subtotal', 14, 2)->default('0.00');
            $table->decimal('discount', 14, 2)->default('0.00');
            $table->decimal('shipping', 14, 2)->default('0.00');
            $table->decimal('tax', 14, 2)->default('0.00');
            $table->decimal('total', 14, 2)->default('0.00');
            $table->decimal('paid', 14, 2)->default('0.00');
            $table->decimal('due', 14, 2)->default('0.00');
            $table->string('payment_method', 32)->nullable();
            $table->string('amount_in_words', 255)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('template', 32)->default('standard');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['series', 'year', 'sequence'], 'invoices_series_seq_unique');
            $table->unique(['type', 'order_id'], 'invoices_type_order_unique');
            $table->unique(['type', 'purchase_id'], 'invoices_type_purchase_unique');
            $table->index(['type', 'issued_at'], 'invoices_type_issued_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
