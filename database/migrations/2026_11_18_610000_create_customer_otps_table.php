<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time sign-in codes for storefront accounts (P4-STO-11). The phone and the code are
 * stored only as keyed hashes (HMAC with the app key): this table never holds a phone
 * number or a usable code (rule 20). A row is one code sent; resend cooldown and the
 * hourly cap count rows per `phone_hash`, attempts are counted per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_otps', function (Blueprint $table): void {
            $table->id();
            $table->char('phone_hash', 64);
            $table->char('code_hash', 64);
            $table->string('purpose', 16)->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['phone_hash', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_otps');
    }
};
