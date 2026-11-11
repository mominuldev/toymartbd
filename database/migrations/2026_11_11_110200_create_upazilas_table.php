<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3 (Customer · geography) — upazilas, P3-DAT-03.
 *
 * `is_inside_dhaka_metro` / `is_dhaka_subcity` drive the three-tier delivery-charge
 * zone (§5.1: inside_dhaka_metro | dhaka_suburb | outside_dhaka). Their values come from
 * database/data/geo/dhaka-zones.json and are provisional pending the owner's decision.
 * They describe OUR charge tier only — never a courier's zone coverage (rule 32).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upazilas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->string('name', 64);
            $table->string('name_bn', 128);
            $table->string('pcode', 16)->nullable()->unique();
            $table->boolean('is_inside_dhaka_metro')->default(false)->index();
            $table->boolean('is_dhaka_subcity')->default(false)->index();
            $table->timestamps();

            $table->unique(['district_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upazilas');
    }
};
