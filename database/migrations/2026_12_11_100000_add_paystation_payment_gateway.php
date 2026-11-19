<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PayStation's switchboard row (owner request 2026-11-19). Starts switched off, unverified
 * and in sandbox mode, like SSLCommerz: production never takes money through it until the
 * owner has tested a real merchant account end to end and verified it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payment_gateways')->where('code', 'paystation')->exists()) {
            return;
        }

        DB::table('payment_gateways')->insert([
            'code' => 'paystation',
            'name' => 'PayStation',
            'is_active' => false,
            'verified' => false,
            'sandbox' => true,
            'position' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('payment_gateways')->where('code', 'paystation')->delete();
    }
};
