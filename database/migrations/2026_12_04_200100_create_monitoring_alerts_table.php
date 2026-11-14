<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10 business alerts (SYSTEM-ARCHITECTURE §11): one row per alert name holding its current
 * state, which is what makes the scheduled checks idempotent and de-duplicated — the owner is
 * told once when an alert starts firing, reminded every `renotify_minutes` while it keeps
 * firing, and told once when it resolves. A re-run in the same state sends nothing.
 * `last_test_at` records the last `toymart:alerts:test` send (launch checklist: "business
 * alerts firing on a test condition"). `summary` is an operator sentence, never PII (rule 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_alerts', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->string('state', 16)->default('ok')->comment('AlertState: ok|firing');
            $table->string('severity', 16)->nullable()->comment('AlertSeverity: warning|critical');
            $table->string('summary', 500)->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('notify_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alerts');
    }
};
