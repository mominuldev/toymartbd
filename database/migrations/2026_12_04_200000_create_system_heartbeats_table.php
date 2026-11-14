<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10 monitoring (SYSTEM-ARCHITECTURE §11, docs/ops/observability.md): the last time each
 * background duty succeeded (and last failed) — the scheduler's minute beat, the nightly
 * backup, the monthly restore rehearsal, every courier's last API answer, the synthetic
 * add-to-cart check. One row per key, overwritten in place: a projection of "is it still
 * running", not a history (the logs hold that). Kept in the database, not the cache, so a
 * cache flush or a Redis restart cannot make a stale backup look fresh — or unknown.
 * `meta` is small operational detail only (counts, durations); never PII (rule 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
