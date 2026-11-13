<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 batch 1 (Phase 8 closeout): a failed scheduled-report run can be resent by staff for the
 * same period. `resend_count` and the last resender/time sit on the run for the admin's
 * sent-log; every resend is also written to `audit_sensitive`. Expand only (nullable / defaulted
 * columns on a small table). FK target `users` exists from the first migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedule_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->foreignId('last_resent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_resent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_schedule_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_resent_by');
            $table->dropColumn(['resend_count', 'last_resent_at']);
        });
    }
};
