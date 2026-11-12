<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.4 `carts` (P4-CRT-01). Server-side cart keyed by an opaque
 * random token that the Next.js server keeps in an httpOnly cookie and forwards as
 * `X-Cart-Token`. The money columns cache the last priced result; the API always
 * re-prices through PricingPipeline, and checkout never trusts them.
 *
 * Additions to §3.4: `converted_at` + `order_id` ("mark cart converted", §4.1) and
 * `merged_into_cart_id` (a guest cart merged on login). `customer_id`, `order_id`,
 * `recovered_order_id` and `shipping_address_id` carry no FK yet — their tables are
 * created by the Customer/Ordering migrations; the constraints follow there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->char('currency', 3)->default('BDT');
            $table->decimal('subtotal', 14, 2)->default('0.00');
            $table->decimal('discount_total', 14, 2)->default('0.00');
            $table->decimal('shipping_total', 14, 2)->default('0.00');
            $table->decimal('grand_total', 14, 2)->default('0.00');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('recovery_email_sent_at')->nullable();
            $table->unsignedBigInteger('recovered_order_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('merged_into_cart_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['customer_id', 'converted_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
