<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.7 `financial_transactions` — the append-only ledger (rule 8).
 *
 * `amount` is DECIMAL(14,2), always ≥ 0; `direction` carries the sign. `occurred_on` is a
 * Dhaka calendar date (rule 11). `is_recognised_revenue` (belongs in the P&L) and
 * `is_cash_movement` (real money moved) carry the revenue-versus-cash split (rule 10).
 *
 * UNIQUE (source_type, source_id, type) is the idempotency key: a replayed event cannot
 * write an entry twice. No `updated_at`: rows are never updated (the model throws).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32)->comment('LedgerEntryType');
            $table->string('direction', 3)->comment('Direction: in|out');
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on')->comment('Dhaka calendar date');
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->boolean('is_cash_movement')->default(false);
            $table->boolean('is_recognised_revenue')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->unique(['source_type', 'source_id', 'type'], 'financial_transactions_source_type_unique');
            $table->index(['occurred_on', 'type']);
            $table->index(['type', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
