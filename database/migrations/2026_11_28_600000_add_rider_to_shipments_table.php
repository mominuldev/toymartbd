<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery rider on a shipment (owner decision 2026-11-13): the "out for delivery"
 * message names the RIDER's phone, never our hotline. Neither courier's official API
 * returns the rider's number, so staff record it when the rider calls (Admin → order →
 * shipment card → Rider); `rider_source` says how we learned it (`manual` today; `courier`
 * reserved for a provider that one day publishes it).
 *
 * Expand-only: nullable columns, no backfill, no index — read by shipment id / order id,
 * which are already indexed. `rider_phone` is E.164 (rule 30), never logged in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('rider_name', 100)->nullable()->after('note');
            $table->string('rider_phone', 16)->nullable()->after('rider_name');
            $table->string('rider_source', 16)->nullable()->after('rider_phone');
            $table->timestamp('rider_recorded_at')->nullable()->after('rider_source');
            $table->foreignId('rider_recorded_by')->nullable()->after('rider_recorded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rider_recorded_by');
            $table->dropColumn(['rider_name', 'rider_phone', 'rider_source', 'rider_recorded_at']);
        });
    }
};
