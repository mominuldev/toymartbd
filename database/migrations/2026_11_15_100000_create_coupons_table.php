<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.8 `coupons` (P4-CRT-04). `code` is stored upper-case and matched
 * case-insensitively by upper-casing the input. `conditions` holds the scope ids for
 * `applies_to` (`{"ids": [..]}`); `excludes` holds `{product_ids, category_ids,
 * brand_ids}`. `used_count` is maintained under the coupon's row lock by
 * CouponUsages::record() — never by a bare increment elsewhere.
 * `customer_segment_id` has no FK: segments arrive with Marketing in Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            $table->string('type', 20);
            $table->decimal('value', 14, 2)->default('0.00');
            $table->decimal('max_discount', 14, 2)->nullable();
            $table->decimal('min_order_amount', 14, 2)->default('0.00');
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('applies_to', 20)->default('all');
            $table->json('conditions')->nullable();
            $table->json('excludes')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->unsignedBigInteger('customer_segment_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
