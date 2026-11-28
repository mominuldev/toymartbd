<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * bKash Tokenized Checkout's switchboard row (P10, docs/_research/bkash-api.md). Starts switched
 * off, unverified and in sandbox mode, like SSLCommerz and PayStation: no bKash merchant account
 * exists yet (Gate 1 decision 5 open), so production never takes money through it until the
 * owner has proven a real live payment and verified it from the CLI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payment_gateways')->where('code', 'bkash')->exists()) {
            return;
        }

        DB::table('payment_gateways')->insert([
            'code' => 'bkash',
            'name' => 'bKash',
            'is_active' => false,
            'verified' => false,
            'sandbox' => true,
            'position' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('payment_gateways')->where('code', 'bkash')->delete();
    }
};
