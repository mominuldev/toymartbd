<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `supplier_payments` — money paid to a supplier, optionally
 * against one PO. Immutable once written: the ledger entry it produces
 * (`supplier_payment`, cash out, not revenue) is append-only (rule 8), so a mistaken
 * payment is corrected by a new entry, never an edit. `paid_at` is the Dhaka calendar
 * date the money left (rule 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 24)->comment('SupplierPaymentMethod');
            $table->string('reference', 120)->nullable();
            $table->date('paid_at');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
