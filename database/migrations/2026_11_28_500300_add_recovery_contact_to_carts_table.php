<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-cart recovery (P7): the contact a guest left on the cart. `phone` is E.164;
 * `marketing_consent` is the explicit opt-in a guest gave for a reminder (a signed-in
 * customer's notification preferences decide for them, and an explicit opt-out always
 * wins). `locale` is the language the reminder is written in. Nullable, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('phone', 16)->nullable()->after('customer_id');
            $table->boolean('marketing_consent')->default(false)->after('phone');
            $table->string('locale', 2)->nullable()->after('marketing_consent');
            $table->timestamp('contact_captured_at')->nullable()->after('locale');

            $table->index(['converted_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropIndex(['converted_at', 'updated_at']);
            $table->dropColumn(['phone', 'marketing_consent', 'locale', 'contact_captured_at']);
        });
    }
};
