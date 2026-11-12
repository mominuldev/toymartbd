<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order numbers `TMB-26-00001` (DATABASE-DESIGN §3.4): prefix, two-digit Dhaka year,
 * a per-year counter. One row per year, incremented under `SELECT … FOR UPDATE` inside
 * PlaceOrder's transaction, so two concurrent checkouts can never draw the same number
 * and a rolled-back checkout gives its number back (no gaps from failed orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary()->comment('Dhaka calendar year');
            $table->unsignedInteger('last_value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
