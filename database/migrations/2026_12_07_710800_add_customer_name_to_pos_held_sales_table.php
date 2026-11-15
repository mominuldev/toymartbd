<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A held sale keeps the customer's name beside the phone, so resuming puts the named customer
 * back on the register (selling on due needs a name, rule 33c). Expand-only, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_held_sales', function (Blueprint $table): void {
            $table->string('customer_name', 160)->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('pos_held_sales', function (Blueprint $table): void {
            $table->dropColumn('customer_name');
        });
    }
};
