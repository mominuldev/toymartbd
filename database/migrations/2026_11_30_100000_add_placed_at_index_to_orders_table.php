<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 dashboard: "orders placed" reads `orders` by a placed_at range (Dhaka day bounds as UTC
 * instants). §6 indexes created_at, not placed_at, so the range was a full scan. Additive,
 * online on MySQL 8.4 (ALGORITHM=INPLACE, no lock on reads or writes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['placed_at', 'status'], 'orders_placed_at_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_placed_at_status_idx');
        });
    }
};
