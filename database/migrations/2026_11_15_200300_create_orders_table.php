<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.4 `orders` (P4-ORD-01).
 *
 * Three status columns, not one (§3.4): `status` (business workflow, OrderStatus),
 * `payment_status` (money owed/received, OrderPaymentStatus) and `fulfillment_status`
 * (physical goods, FulfillmentStatus). Each moves only through its guarded state
 * machine in App\Domain\Ordering\Services.
 *
 * Orders are never deleted or soft-deleted (rule 12): there is no `deleted_at`, the
 * customer FK restricts, and the model refuses delete().
 *
 * Money is DECIMAL(14,2) (rule 6). The address is a snapshot — names as they read at
 * placement, plus the geography ids (an addition to §3.4) so courier zone mapping and
 * the delivery-charge resolver never have to match on a name (rule 32).
 *
 * `cart_id` is a plain id: carts are owned by the Cart domain and may be purged.
 * `payment_method` is denormalised from the order's first payment for the order board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 20)->unique()->comment('TMB-YY-NNNNN');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->string('status', 24)->default('pending')->comment('OrderStatus');
            $table->string('payment_status', 24)->default('unpaid')->comment('OrderPaymentStatus');
            $table->string('fulfillment_status', 24)->default('unfulfilled')->comment('FulfillmentStatus');
            $table->string('source', 16)->default('web')->comment('OrderSource');
            $table->string('channel_reference', 191)->nullable();
            $table->string('payment_method', 24)->nullable()->comment('PaymentMethod of the first payment');
            $table->char('currency', 3)->default('BDT');

            // Money (rule 6). §7.3: grand_total = subtotal − discount_total + shipping_charge + tax_total.
            $table->decimal('subtotal', 14, 2)->default('0.00');
            $table->decimal('discount_total', 14, 2)->default('0.00');
            $table->string('coupon_code', 64)->nullable();
            $table->decimal('coupon_discount', 14, 2)->default('0.00');
            $table->decimal('promotion_discount', 14, 2)->default('0.00');
            $table->decimal('shipping_charge', 14, 2)->default('0.00');
            $table->decimal('shipping_discount', 14, 2)->default('0.00');
            $table->decimal('tax_total', 14, 2)->default('0.00');
            $table->decimal('grand_total', 14, 2)->default('0.00');
            $table->decimal('paid_total', 14, 2)->default('0.00');
            $table->decimal('due_total', 14, 2)->default('0.00');
            $table->decimal('refunded_total', 14, 2)->default('0.00');

            // Shipping address snapshot (rule 31: landmark is never dropped).
            $table->string('shipping_name', 160);
            $table->string('shipping_phone', 16)->comment('E.164');
            $table->string('shipping_alt_phone', 16)->nullable();
            $table->unsignedBigInteger('shipping_division_id')->nullable();
            $table->unsignedBigInteger('shipping_district_id')->nullable();
            $table->unsignedBigInteger('shipping_upazila_id')->nullable();
            $table->string('shipping_division', 128)->nullable();
            $table->string('shipping_district', 128)->nullable();
            $table->string('shipping_upazila', 128)->nullable();
            $table->string('shipping_area', 160)->nullable();
            $table->string('shipping_address', 500);
            $table->string('shipping_landmark', 255)->nullable();
            $table->string('shipping_postcode', 8)->nullable();

            // Billing snapshot — null means "same as shipping".
            $table->string('billing_name', 160)->nullable();
            $table->string('billing_phone', 16)->nullable();
            $table->string('billing_division', 128)->nullable();
            $table->string('billing_district', 128)->nullable();
            $table->string('billing_upazila', 128)->nullable();
            $table->string('billing_area', 160)->nullable();
            $table->string('billing_address', 500)->nullable();
            $table->string('billing_landmark', 255)->nullable();

            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('gift_message', 500)->nullable();
            $table->boolean('is_gift')->default(false);

            // Risk (§5.2). Advisory only: never auto-cancels (rules 23, 24b).
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('risk_level', 8)->nullable()->comment('low|medium|high');
            $table->json('risk_flags')->nullable();
            $table->string('verification_status', 16)->default('not_required')->comment('VerificationStatus');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            // Timeline.
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('cancel_reason', 32)->nullable()->comment('CancellationReason');
            $table->string('cancel_note', 500)->nullable();
            $table->string('return_reason', 500)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // DATABASE-DESIGN §6.
            $table->index(['status', 'created_at']);
            $table->index('payment_status');
            $table->index(['customer_id', 'created_at']);
            $table->index('shipping_phone');
            $table->index(['risk_level', 'status']);
            $table->index('delivered_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
