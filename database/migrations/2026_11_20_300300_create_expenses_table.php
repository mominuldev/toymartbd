<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.7 `expenses` (PRD N-04: categories, attachments, approval).
 *
 * Additions to §3.7: `status` (ExpenseStatus: pending → approved | rejected; approved → void),
 * `approved_at`, `rejected_reason`, `paid_on` (null with a null `payment_method` = unpaid),
 * `attachment_name`/`_mime`/`_size` (the receipt on the private originals disk),
 * `recurrence_parent_id` + `recurrence_period` (a generated copy of a recurring template,
 * UNIQUE so a re-run never generates twice), and the void trail. `shipment_id` has no FK:
 * shipments arrive in Phase 6. `expense_date` is a Dhaka calendar date (rule 11).
 *
 * An expense touches the ledger only when approved (an `out`, recognised entry; cash too if
 * paid) and when voided (the reversing entry). Pending and rejected expenses never do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('expense_date')->comment('Dhaka calendar date');
            $table->string('status', 16)->default('pending')->comment('ExpenseStatus');
            $table->string('payment_method', 24)->nullable()->comment('ExpensePaymentMethod; null = unpaid');
            $table->date('paid_on')->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('vendor', 160)->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 191)->nullable();
            $table->string('attachment_mime', 64)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence', 16)->nullable()->comment('ExpenseRecurrence');
            $table->foreignId('recurrence_parent_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->char('recurrence_period', 7)->nullable()->comment('Y-m of a generated copy');
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['recurrence_parent_id', 'recurrence_period']);
            $table->index(['expense_date', 'category_id']);
            $table->index(['status', 'expense_date']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
