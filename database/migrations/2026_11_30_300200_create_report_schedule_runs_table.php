<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sent-log of scheduled reports. UNIQUE (schedule, period_from, period_to) is the
 * idempotency key: the 15-minute sweep claims a period by inserting its row, so a second
 * sweep — or a second server — in the same period inserts nothing and sends nothing.
 * A failed run stays failed; it is never re-sent automatically (no double send to the
 * recipients who already got it). FK target report_schedules (300000) exists before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedule_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->date('period_from')->comment('Dhaka date, inclusive');
            $table->date('period_to')->comment('Dhaka date, inclusive');
            $table->string('status', 16)->default('queued')->comment('ReportRunStatus');
            $table->unsignedSmallInteger('recipients_sent')->default(0);
            $table->unsignedSmallInteger('recipients_skipped')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('due_at')->nullable()->comment('the scheduled send instant (UTC)');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['report_schedule_id', 'period_from', 'period_to'], 'report_schedule_runs_period_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_runs');
    }
};
