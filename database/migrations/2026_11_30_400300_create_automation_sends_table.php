<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per automation decision (P8): review request (subject = order, period = '') and
 * birthday message (subject = customer, period = Dhaka year). UNIQUE (automation, subject,
 * period) makes a second run — or two schedulers — unable to message anyone twice.
 * `queued` points at the notification log row; `skipped` says why (no_consent, opted_out,
 * no_phone, template_inactive, …), so "consent respected" is provable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_sends', function (Blueprint $table): void {
            $table->id();
            $table->string('automation', 32)->comment('review_request | birthday');
            $table->unsignedBigInteger('subject_id')->comment('orders.id or customers.id');
            $table->string('period', 16)->default('');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status', 16)->comment('queued | skipped');
            $table->string('skip_reason', 32)->nullable();
            $table->unsignedBigInteger('notification_log_id')->nullable();
            $table->timestamps();

            $table->unique(['automation', 'subject_id', 'period'], 'automation_sends_once');
            $table->index(['automation', 'created_at'], 'automation_sends_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_sends');
    }
};
