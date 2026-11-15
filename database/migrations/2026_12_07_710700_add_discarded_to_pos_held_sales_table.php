<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discard a held sale (POS F8 "Held sales"): a parked cart the cashier throws away. Soft state
 * (expand-only): `discarded_at` / `discarded_by`, set once under the hold's row lock. A
 * discarded hold leaves the drawer's holds list and can never be resumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_held_sales', function (Blueprint $table): void {
            $table->timestamp('discarded_at')->nullable()->after('resumed_by');
            $table->foreignId('discarded_by')->nullable()->after('discarded_at')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_held_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discarded_by');
            $table->dropColumn('discarded_at');
        });
    }
};
