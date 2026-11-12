<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing a partly received PO short (owner decision 2026-11-13): the supplier will not
 * send the rest. Status `closed_short` (PurchaseStatus) plus who closed it, when and why.
 * Expand-only: nullable columns, no rewrite of existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('cancelled_at');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('close_reason', 500)->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'close_reason']);
        });
    }
};
