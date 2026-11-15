<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cashier's discount at the POS (owner decision 2026-11-15), beside the coupon and promotion
 * discounts: discount_total = promotion_discount + coupon_discount + manual_discount.
 * Expand-only: a defaulted column, added INSTANT on MySQL 8.4 (no table lock on `orders`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('manual_discount', 14, 2)->default('0.00')->after('promotion_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('manual_discount');
        });
    }
};
