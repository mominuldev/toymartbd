<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff recipients of a report schedule (never a customer, never a free-typed address).
 * FK targets report_schedules (300000) and users exist before this file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedule_recipients', function (Blueprint $table): void {
            $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['report_schedule_id', 'user_id'], 'report_schedule_recipients_pk');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_recipients');
    }
};
