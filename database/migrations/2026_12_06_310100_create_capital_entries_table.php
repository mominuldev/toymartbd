<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner capital in and out (P10). Insert-only, like the ledger it feeds (rule 8): each row
 * writes one `owner_capital_in` (contribution) or `owner_drawing` (drawing) entry — a cash
 * movement, never revenue (rule 10). A mistake is fixed by a reversing row (`reversal_of_id`,
 * UNIQUE: an entry is reversed at most once), never by an edit. `occurred_on` is a Dhaka date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16)->comment('contribution|drawing');
            $table->foreignId('holder_id')->constrained('capital_holders')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on')->comment('Dhaka calendar date');
            $table->string('method', 20)->comment('ExpensePaymentMethod value');
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('reversal_of_id', 'capital_entries_reversal_of_unique');
            $table->foreign('reversal_of_id', 'capital_entries_reversal_of_fk')->references('id')->on('capital_entries')->restrictOnDelete();
            $table->index(['holder_id', 'occurred_on'], 'capital_entries_holder_date_idx');
            $table->index(['occurred_on', 'id'], 'capital_entries_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_entries');
    }
};
