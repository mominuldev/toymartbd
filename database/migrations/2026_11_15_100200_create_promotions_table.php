<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.8 `promotions` (P4-CRT-02). The pricing pipeline applies active
 * promotions in descending `priority` (ties by id) and stops after the first
 * non-stackable one that matches. `rules` shape per type: docs/api/cart.md.
 * `name_bn` is an addition to §3.8 — the name is shown in the cart breakdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('type', 30);
            $table->integer('priority')->default(0);
            $table->boolean('is_stackable')->default(true);
            $table->json('rules');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('banner_path')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
