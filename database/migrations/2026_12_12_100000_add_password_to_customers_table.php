<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront password sign-in (owner decision 2026-11-20): a customer may sign in with phone +
 * password as well as phone + SMS code. Additive and nullable — guest-checkout customers and
 * accounts made before this have no password until they set one. Only the hash is stored
 * (Laravel `hashed` cast); `password_set_at` records when it was last set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('password')->nullable()->after('phone_verified_at');
            $table->timestamp('password_set_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['password', 'password_set_at']);
        });
    }
};
