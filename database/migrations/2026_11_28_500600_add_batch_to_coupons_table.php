<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon builder (P7): codes generated together in bulk share a `batch` label, so the
 * admin can list, report on and deactivate them as one campaign. Nullable, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->string('batch', 40)->nullable()->after('description');
            $table->index('batch');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropIndex(['batch']);
            $table->dropColumn('batch');
        });
    }
};
