<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 scheduled report emails: one row per schedule — which report, how often, the Dhaka
 * send time, the email's language and the attachment format. Recipients are staff users
 * (report_schedule_recipients); every send is logged once per period in report_schedule_runs.
 * FK target `users` exists from the first migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('report_type', 24)->comment('ReportType: sales|pnl|couriers');
            $table->string('frequency', 16)->comment('ReportFrequency: daily|weekly|monthly');
            $table->char('send_time', 5)->default('08:00')->comment('HH:MM, Asia/Dhaka');
            $table->char('locale', 2)->default('en')->comment('en|bn');
            $table->string('format', 8)->default('xlsx')->comment('ReportFileFormat: csv|xlsx');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'frequency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
