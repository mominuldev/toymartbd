<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 (as built, P4 batch 5) — the VAT rate included in a product's
 * selling price, in percent (0–100, two decimals). The server takes it out of the price
 * before computing margin. Toys are VAT-exempt at retail by default (0), pending the
 * accountant's confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('vat_rate', 5, 2)->default(0)->after('warranty_months');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('vat_rate');
        });
    }
};
