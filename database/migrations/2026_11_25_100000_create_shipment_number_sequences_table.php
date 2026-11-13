<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment numbers `SHP-26-000001` (P6): prefix, two-digit Dhaka year, a per-year counter
 * drawn under `SELECT … FOR UPDATE` inside RequestShipment's transaction — the same scheme
 * as `order_number_sequences`. The number is also the merchant order reference sent to
 * the courier, so one shipment is one reference however often its booking is retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_number_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary()->comment('Dhaka calendar year');
            $table->unsignedInteger('last_value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_number_sequences');
    }
};
