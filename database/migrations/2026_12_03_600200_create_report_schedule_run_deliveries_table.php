<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 batch 1 (Phase 8 closeout): one row per recipient of a scheduled report run — who got
 * the period's email, who was skipped (no longer allowed to see the report) and whose copy
 * failed. It is what makes a manual resend exact: a resend mails only the recipients whose
 * copy failed (or one chosen recipient), never one who already has it. UNIQUE (run, user).
 * `resent_by`/`resent_at` record the last staff member who asked for this copy again (the
 * full trail is in `audit_sensitive`). FK targets report_schedule_runs (2026_11_30_300200)
 * and users exist before this file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedule_run_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_schedule_run_id')->constrained('report_schedule_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('queued')->comment('ReportDeliveryStatus: queued|sent|skipped|failed');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('resent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resent_at')->nullable();
            $table->timestamps();

            $table->unique(['report_schedule_run_id', 'user_id'], 'rs_run_deliveries_run_user_unique');
            $table->index('user_id', 'rs_run_deliveries_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_run_deliveries');
    }
};
