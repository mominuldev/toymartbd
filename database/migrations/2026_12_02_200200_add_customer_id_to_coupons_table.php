<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A personal coupon (P8 batch 3, birthday-reminder offers): `customer_id` set = the code is
 * valid only for that customer (their account, or their phone at checkout). Null = anyone, as
 * before. Nullable and additive; customers are never deleted, so the FK restricts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('customer_segment_id')->constrained('customers');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
