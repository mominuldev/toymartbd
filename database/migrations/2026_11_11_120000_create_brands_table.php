<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `brands` (P3-ADM-04 / P4-CAT): `id · name · slug UNIQUE ·
 * logo_path · description · country · status · position`, plus the Bangla columns
 * every customer-facing catalog table carries (rule 35) and timestamps.
 *
 * A brand is archived (`status = archived`), never deleted: products will hold a
 * `brand_id` FK. Hence no `deleted_at` — the doc gives brands none, unlike products
 * and categories.
 *
 * `slug` is capped at 94 so the revalidation tag `brand:{slug}` fits the receiver's
 * 100-character tag limit (docs/api/revalidation.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('name_bn', 120)->nullable();
            $table->string('slug', 94)->unique();
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();
            $table->char('country', 2)->nullable()->comment('ISO 3166-1 alpha-2 country of origin');
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
