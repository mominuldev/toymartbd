<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 — what a stock location is for, as the inventory location cards
 * show it under the name ("Main warehouse · online orders", "Retail branch · POS +
 * pickup"; Figma B · 14 Inventory 241:39526). Expand-only: two nullable columns, no
 * backfill, nothing reads them as required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->string('description', 160)->nullable()->after('name_bn');
            $table->string('description_bn', 200)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->dropColumn(['description', 'description_bn']);
        });
    }
};
