<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit of a cashier's discount: what was asked (`percent` of the goods or a flat `amount`),
 * what it came to, and why. The cashier is the sale's `cashier_id`. Written once with the sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->decimal('manual_discount', 14, 2)->default('0.00')->after('grand_total');
            $table->string('discount_type', 8)->nullable()->after('manual_discount')->comment('percent | amount');
            $table->decimal('discount_value', 14, 2)->nullable()->after('discount_type');
            $table->string('discount_reason', 160)->nullable()->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropColumn(['manual_discount', 'discount_type', 'discount_value', 'discount_reason']);
        });
    }
};
