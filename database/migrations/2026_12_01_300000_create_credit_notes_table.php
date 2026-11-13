<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes (P8 batch 2, PRD N-13 follow-up): the document that corrects an issued
 * customer invoice when the order is cancelled after issue or a refund is paid on a return.
 * One immutable row per credit note, numbered `CN-YY-NNNNNN` per Dhaka year from the same
 * `invoice_number_sequences` table (series `CN`), gap-free.
 *
 * A document only: it never writes the ledger — the cancellation and the refund already
 * post their own `financial_transactions` rows.
 *
 *  - `source_type` / `source_id` — what caused it: `order_cancellation` (source = the
 *    order) or `refund` (source = the refund). UNIQUE, so a re-delivered event can never
 *    issue a second credit note for the same cause.
 *  - `invoice_number` / `invoice_issued_at` — copied from the original invoice for the PDF.
 *  - `issued_by` (company now), `issued_to` (the invoice's recipient), `lines` and the
 *    totals are a snapshot; the PDF is re-rendered from them.
 *  - `subtotal` (credited lines) − `discount` + `shipping` (delivery charge credited) +
 *    `tax` + `adjustment` (signed; e.g. a refund capped below its computed lines) = `total`,
 *    mirroring the invoice's own totals. All DECIMAL(14,2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('series', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 32)->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('invoice_number', 32);
            $table->timestamp('invoice_issued_at');
            $table->string('reason', 32)->comment('order_cancelled | refund');
            $table->string('source_type', 32)->comment('order_cancellation | refund');
            $table->unsignedBigInteger('source_id');
            $table->string('source_reference', 64)->nullable()->comment('order number, or refund + RMA number');
            $table->string('note', 500)->nullable();
            $table->json('issued_to');
            $table->json('issued_by');
            $table->json('lines');
            $table->string('currency', 3)->default('BDT');
            $table->decimal('subtotal', 14, 2)->default('0.00');
            $table->decimal('discount', 14, 2)->default('0.00');
            $table->decimal('shipping', 14, 2)->default('0.00');
            $table->decimal('tax', 14, 2)->default('0.00');
            $table->decimal('adjustment', 14, 2)->default('0.00');
            $table->decimal('total', 14, 2)->default('0.00');
            $table->string('amount_in_words', 255)->nullable();
            $table->timestamp('issued_at');
            $table->string('template', 32)->default('standard');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['series', 'year', 'sequence'], 'credit_notes_series_seq_unique');
            $table->unique(['source_type', 'source_id'], 'credit_notes_source_unique');
            $table->index(['issued_at'], 'credit_notes_issued_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
