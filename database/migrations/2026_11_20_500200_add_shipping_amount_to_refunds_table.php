<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery-charge part of a refund, so it is refunded at most once per order. Additive;
 * `refunds` has no production rows yet (the refund flow ships with this change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->decimal('shipping_amount', 14, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('shipping_amount');
        });
    }
};
