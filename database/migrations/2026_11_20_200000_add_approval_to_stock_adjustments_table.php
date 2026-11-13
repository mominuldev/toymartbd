<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5-INV approval flow on `stock_adjustments` (expand-only: every column nullable or
 * defaulted, existing `applied` rows unchanged). `requested_units` / `requested_value`
 * are the size of the change when it was requested — what the approval threshold was
 * measured against (units moved, and their value at weighted-average cost). The
 * decision is `approved_by` + `approved_at`, or `rejected_by` + `rejected_at`, with an
 * optional `decision_note`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->unsignedInteger('requested_units')->default(0)->after('status');
            $table->decimal('requested_value', 14, 2)->default(0)->after('requested_units');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('decision_note', 500)->nullable()->after('rejected_at');

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['requested_units', 'requested_value', 'approved_at', 'rejected_at', 'decision_note']);
        });
    }
};
