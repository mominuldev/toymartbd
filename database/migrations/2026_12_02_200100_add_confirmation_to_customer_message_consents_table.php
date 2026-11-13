<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How staff confirmed a customer's consent (P8 batch 3, owner decision 2026-11-13):
 * `phone_call | in_person | written | other` plus an optional short note. Set when staff record
 * a "yes" in the admin; null for a storefront opt-in (the customer ticked it themselves).
 * Nullable and additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_message_consents', function (Blueprint $table): void {
            $table->string('confirmation_method', 16)->nullable()->after('recorded_by');
            $table->string('confirmation_note', 200)->nullable()->after('confirmation_method');
        });
    }

    public function down(): void
    {
        Schema::table('customer_message_consents', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_method', 'confirmation_note']);
        });
    }
};
