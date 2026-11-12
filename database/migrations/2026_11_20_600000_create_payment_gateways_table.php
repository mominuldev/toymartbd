<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_gateways` (P4-PAY-02) — one row per online driver, mirroring
 * `courier_providers.verified` (rule 33a): an online method is offered only while the
 * row is active, has credentials, and — in production — is `verified`. No merchant account
 * exists yet (Gate 1 decision 5), so SSLCommerz is seeded inactive and unverified.
 *
 * `credentials` is the encrypted cast (rule 20). Sandbox credentials come from env
 * (config/payments.php); a stored value, once an owner enters one, takes precedence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique()->comment('PaymentMethod value of the driver');
            $table->string('name', 80);
            $table->boolean('is_active')->default(false);
            $table->boolean('verified')->default(false)->comment('rule 33a spirit: no production payment while false');
            $table->boolean('sandbox')->default(true);
            $table->text('credentials')->nullable()->comment('encrypted JSON (rule 20)');
            $table->json('settings')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        DB::table('payment_gateways')->insert([
            'code' => 'sslcommerz',
            'name' => 'SSLCommerz',
            'is_active' => false,
            'verified' => false,
            'sandbox' => true,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
