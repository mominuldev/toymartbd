<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 / §9 — stock locations (P4-CRT-05). MVP has exactly one, seeded
 * here (it is reference data every environment needs, not demo data), so multi-warehouse
 * later is a data change rather than a migration. `is_default` marks the location that
 * opening stock, sales and the storefront read from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('name_bn', 160)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('stock_locations')->insert([
            'code' => 'main',
            'name' => 'Main warehouse',
            'name_bn' => 'প্রধান গুদাম',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
