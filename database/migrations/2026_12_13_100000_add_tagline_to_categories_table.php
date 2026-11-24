<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The category's short line under its home tile (Smart Junior "Category Tile" subtitle, Figma
 * 2b6PVOOapPbcaKYIdHWZup 34:5709 — "শেখা ও খেলা"). Additive and nullable: no backfill, a tile
 * without one shows nothing (or its count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('tagline', 60)->nullable()->after('description_bn');
            $table->string('tagline_bn', 60)->nullable()->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['tagline', 'tagline_bn']);
        });
    }
};
