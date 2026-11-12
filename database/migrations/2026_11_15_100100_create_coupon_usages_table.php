<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.8 `coupon_usages` (P4-CRT-04). `phone` (E.164) is the per-customer
 * key, so the limit holds across guest checkouts on the same phone. `order_id` and
 * `customer_id` carry no FK yet: `orders`/`customers` are created by the Ordering and
 * Customer migrations of the same batch, and the constraint is added there (expand →
 * contract) once both tables exist. One usage per (coupon, order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('phone', 16);
            $table->decimal('discount_amount', 14, 2)->default('0.00');
            $table->timestamp('used_at');
            $table->timestamps();

            $table->unique(['coupon_id', 'order_id']);
            $table->index(['coupon_id', 'phone']);
            $table->index(['coupon_id', 'customer_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
