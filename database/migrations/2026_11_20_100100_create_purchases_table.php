<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `purchases` — the purchase order (P5 purchasing). Never deleted;
 * a PO that will not happen is `cancelled`. Status is a PurchaseStatus state machine:
 * draft → ordered → partially_received → received, or draft/ordered → cancelled.
 *
 * Money (rule 6, DECIMAL(14,2)):
 *   subtotal       Σ quantity_ordered × unit_cost (before line discounts)
 *   discount       Σ line discounts
 *   shipping_cost  Σ freight entered on the receipts (actual, not estimated)
 *   customs_duty   Σ customs / duty entered on the receipts   (as built: not in §3.2's list)
 *   other_cost     Σ other landed costs entered on the receipts
 *   tax            0 at MVP — owner decision pending (supplier VAT/AIT treatment)
 *   total          subtotal − discount + shipping_cost + customs_duty + other_cost + tax
 *   received_value what the supplier is owed for what has arrived (as built)
 *   paid_amount    Σ supplier_payments linked to this PO
 *   due_amount     received_value − paid_amount (negative = advance)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 24)->default('draft')->comment('PurchaseStatus');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->decimal('customs_duty', 14, 2)->default(0);
            $table->decimal('other_cost', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('received_value', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('due_amount', 14, 2)->default(0);
            $table->string('supplier_invoice_no', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['supplier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
