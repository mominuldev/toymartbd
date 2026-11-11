<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3 (Customer · geography) — static reference data, P3-DAT-03.
 *
 * `id` is the source dataset's own id (database/data/geo/SOURCE.md), so it is a stable
 * key for upserts and for addresses. `pcode` is the official OCHA/BBS COD-AB P-code,
 * the key for any future reconciliation. Never join courier zones on `name` (rule 32).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('name_bn', 128);
            $table->string('pcode', 16)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
