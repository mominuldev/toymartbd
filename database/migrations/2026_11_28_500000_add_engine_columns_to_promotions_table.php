<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions engine (P7, docs/api/promotions.md). Additive columns on `promotions`:
 *
 *  - `allows_coupons` — whether a coupon may also discount the lines this promotion
 *    discounted. Default TRUE keeps the Phase 4 behaviour ("a coupon applies after a
 *    non-stackable promotion"); the admin form shows it explicitly on every promotion.
 *  - `usage_limit_total` / `usage_limit_per_customer` — orders, keyed per customer on
 *    the E.164 phone (as coupons are), so the limit holds across guest checkouts.
 *  - `budget` / `discount_spent` — a cap on the total goods discount given. Maintained
 *    under the promotion's row lock by PromotionUsages, never by a bare increment.
 *  - `description`, `created_by` — admin bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('description')->nullable()->after('name_bn');
            $table->boolean('allows_coupons')->default(true)->after('is_stackable');
            $table->unsignedInteger('usage_limit_total')->nullable()->after('usage_count');
            $table->unsignedInteger('usage_limit_per_customer')->nullable()->after('usage_limit_total');
            $table->decimal('budget', 14, 2)->nullable()->after('usage_limit_per_customer');
            $table->decimal('discount_spent', 14, 2)->default('0.00')->after('budget');
            $table->foreignId('created_by')->nullable()->after('discount_spent')->constrained('users')->nullOnDelete();

            $table->index(['type', 'is_active', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropIndex(['type', 'is_active', 'ends_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['description', 'allows_coupons', 'usage_limit_total', 'usage_limit_per_customer', 'budget', 'discount_spent']);
        });
    }
};
