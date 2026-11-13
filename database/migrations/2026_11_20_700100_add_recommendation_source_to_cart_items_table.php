<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule 25d: the recommendation placement that added a cart line (a RecommendationPlacement
 * value, NULL for an ordinary add). PlaceOrder copies it onto
 * `order_items.recommendation_source`. Expand-only: a nullable column, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->string('recommendation_source', 32)->nullable()->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn('recommendation_source');
        });
    }
};
