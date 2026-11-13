<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `purchase_receipts` (as built, P5 — §3.2 names receipts only in §4.6's flow): one row
 * per delivery received against a PO, so a partial receipt is a document with its own
 * number (GRN-YYYY-NNNN), location, landed costs and stock movements
 * (`stock_movements.reference_type = 'purchase_receipt'`). Written once by
 * ReceivePurchase and never changed.
 *
 * Landed costs (freight, customs/duty, other) are apportioned across this receipt's
 * lines by `apportion_method` (value by default — §3.2). `goods_value` is Σ line values;
 * `payable_amount` is what the supplier is owed for this delivery: goods, plus the
 * landed costs when `costs_paid_to_supplier` (the supplier charged them on its invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->decimal('freight', 14, 2)->default(0);
            $table->decimal('customs_duty', 14, 2)->default(0);
            $table->decimal('other_cost', 14, 2)->default(0);
            $table->string('apportion_method', 16)->default('value')->comment('LandedCostMethod');
            $table->boolean('costs_paid_to_supplier')->default(true);
            $table->decimal('goods_value', 14, 2);
            $table->decimal('payable_amount', 14, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipts');
    }
};
