<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier settings screen (P6): when the rate card and the credentials were last edited.
 * The booking panel's charge preview says "From the rate card in courier_providers, edited
 * 02 Oct 2026" (04-admin-spec A16) and the settings screen shows when credentials were last
 * replaced — `updated_at` cannot answer either, because toggling a provider touches it too.
 * Expand-only: two nullable columns on a small table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_providers', function (Blueprint $table): void {
            $table->timestamp('rate_card_updated_at')->nullable()->after('return_charge');
            $table->timestamp('credentials_updated_at')->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('courier_providers', function (Blueprint $table): void {
            $table->dropColumn(['rate_card_updated_at', 'credentials_updated_at']);
        });
    }
};
