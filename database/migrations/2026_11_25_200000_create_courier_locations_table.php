<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `courier_locations` (P6): a mirror of a courier's own city → zone →
 * area tree (Pathao only — Steadfast has none), plus an explicit, human-made mapping to our
 * district / upazila. Rows are never matched by name (rule 32): `district_id`, `upazila_id`
 * and `is_mapped` stay NULL/false until a staff member maps the row.
 *
 * One row per zone (`external_area_id` NULL) and one per area. `external_key`
 * (`{city}:{zone}:{area|-}`) is the upsert key, because MySQL treats NULLs in a composite
 * unique index as distinct. A row the provider stops returning is deactivated, never
 * deleted, so its mapping survives a provider-side glitch.
 *
 * `upazila_id` stays NULL for a district-level mapping: a Dhaka-city address has no upazila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('courier_providers')->restrictOnDelete();
            $table->string('external_key', 64)->comment('{city}:{zone}:{area|-} — the upsert key');
            $table->string('external_city_id', 32);
            $table->string('external_city_name', 120);
            $table->string('external_zone_id', 32);
            $table->string('external_zone_name', 160);
            $table->string('external_area_id', 32)->nullable()->comment('NULL = a zone-level row');
            $table->string('external_area_name', 160)->nullable();
            $table->boolean('home_delivery_available')->nullable();
            $table->boolean('pickup_available')->nullable();
            $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
            $table->foreignId('upazila_id')->nullable()->constrained('upazilas')->restrictOnDelete();
            $table->boolean('is_mapped')->default(false)->comment('set only by a human (rule 32)');
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->boolean('is_active')->default(true)->comment('false once the provider stops returning it');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'external_key']);
            $table->index(['provider_id', 'is_mapped']);
            $table->index(['provider_id', 'district_id', 'upazila_id']);
            $table->index(['provider_id', 'external_city_id']);
            $table->index(['provider_id', 'external_zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_locations');
    }
};
