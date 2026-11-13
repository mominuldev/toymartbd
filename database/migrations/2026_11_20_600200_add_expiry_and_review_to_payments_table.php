<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4-PAY-03/04 — additive columns on `payments` (expand only; nullable, no backfill):
 *
 *  - `expires_at`: when an unpaid online attempt stops holding the order's stock
 *    reservation (config payments.online.expiry_minutes). NULL for COD.
 *  - `review_reason` / `review_flagged_at`: the provider reported something we refused to
 *    apply (amount or currency mismatch, paid after the attempt closed). Never marked
 *    paid; staff reconcile it by hand (rule 19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('verified_at');
            $table->string('review_reason', 64)->nullable()->after('expires_at');
            $table->timestamp('review_flagged_at')->nullable()->after('review_reason');

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['expires_at', 'review_reason', 'review_flagged_at']);
        });
    }
};
