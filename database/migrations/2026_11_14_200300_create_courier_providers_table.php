<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `courier_providers` — created now (P4-CRT-03) because the
 * delivery-charge resolver reads the default provider's rate card (§5.1 step 3). The
 * driver columns (credentials, webhook secret, balance) are here so Phase 6 adds a
 * driver, not a migration; nothing reads them yet.
 *
 * Rate card shape: `base_charge_*` per zone covers parcels up to
 * `included_weight_grams`; each further started kilogram adds `per_kg_charge`. The
 * allowance is a column, not a constant, because providers differ — Steadfast steps at
 * 1 kg, Pathao at 2 kg (rule 32a). `verified = false` blocks production booking
 * (rule 33a); it does not affect quoting from the stored rate card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique()->comment('pathao|steadfast|…');
            $table->string('name', 80);
            $table->string('name_bn', 120)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('verified')->default(false)->comment('rule 33a: no production booking while false');
            $table->boolean('requires_location_mapping')->default(false)->comment('Pathao true, Steadfast false (§3.6)');
            $table->text('credentials')->nullable()->comment('encrypted JSON (rule 20)');
            $table->json('settings')->nullable();
            $table->boolean('supports_bulk')->default(false);
            $table->boolean('supports_webhook')->default(false);
            $table->text('webhook_secret')->nullable()->comment('encrypted');
            $table->decimal('base_charge_dhaka', 14, 2)->default('0.00');
            $table->decimal('base_charge_suburb', 14, 2)->default('0.00');
            $table->decimal('base_charge_outside', 14, 2)->default('0.00');
            $table->unsignedInteger('included_weight_grams')->default(1000);
            $table->decimal('per_kg_charge', 14, 2)->default('0.00');
            $table->decimal('cod_fee_percent', 5, 2)->default('0.00');
            $table->decimal('return_charge', 14, 2)->default('0.00');
            $table->decimal('balance', 14, 2)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_providers');
    }
};
