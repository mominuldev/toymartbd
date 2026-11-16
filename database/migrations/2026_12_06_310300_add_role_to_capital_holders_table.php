<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A capital holder is an `owner` or a `partner` (lead decision 2026-11-16, shown on the Capital
 * page). A label only — no share percentage, no profit allocation. Expand-only: a defaulted
 * column, so every existing holder reads as an owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('capital_holders', 'role')) {
            Schema::table('capital_holders', function (Blueprint $table): void {
                $table->string('role', 16)->default('owner')->after('name_bn');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('capital_holders', 'role')) {
            Schema::table('capital_holders', function (Blueprint $table): void {
                $table->dropColumn('role');
            });
        }
    }
};
