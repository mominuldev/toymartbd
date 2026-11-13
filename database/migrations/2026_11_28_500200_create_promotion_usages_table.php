<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One promotion applied to one order (P7). `phone` (E.164) is the per-customer key, as
 * for coupon_usages. `discount_amount` is the goods discount this order took (0 for a
 * free-delivery promotion); `flash_units` = `{variant_id: units}` claimed from the flash
 * allotment, so a cancellation can give exactly those units back. Written and reversed
 * only by PromotionUsages inside the order's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('phone', 16);
            $table->decimal('discount_amount', 14, 2)->default('0.00');
            $table->json('flash_units')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->unique(['promotion_id', 'order_id']);
            $table->index(['promotion_id', 'phone']);
            $table->index(['promotion_id', 'customer_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_usages');
    }
};
