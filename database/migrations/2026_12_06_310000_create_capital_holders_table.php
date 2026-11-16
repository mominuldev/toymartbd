<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner capital (P10, brief-p10-finance "Owner capital — domain decision"): the people who put
 * money into the business or take it out — the owner and any partners. Plain on purpose (owner
 * decision 2026-11-16): no share percentages, no profit allocation. `user_id` optionally links a
 * holder to a staff account. Holders are deactivated, never deleted (entries point at them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_holders', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('name_bn', 120)->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_holders');
    }
};
