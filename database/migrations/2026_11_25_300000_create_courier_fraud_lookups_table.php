<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `courier_fraud_lookups` (P6, rules 24, 24a, 33b): a provider's
 * delivery history for one phone, fetched ONLY from an officially published endpoint
 * (today Steadfast's `GET /fraud_check/{phone}`) and cached with its retrieval time.
 * It is verified external data, displayed beside our own risk score and never merged
 * into it (rule 24). We never store a courier-portal password to scrape (rule 24a).
 *
 * Append-only cache: a fresh lookup inserts a row; the latest per (provider, phone) is
 * read through `(phone, fetched_at)`. Counts are nullable: a provider that does not
 * report a figure stores NULL, never a zero that would read as "no history" (rule 33b).
 * Ratios are fractions 0–1 (DECIMAL(5,4)), NULL when nothing has finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_fraud_lookups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('courier_providers')->restrictOnDelete();
            $table->string('phone', 20)->comment('E.164');
            $table->unsignedInteger('total_parcels')->nullable();
            $table->unsignedInteger('total_delivered')->nullable();
            $table->unsignedInteger('total_cancelled')->nullable();
            $table->unsignedInteger('total_fraud_reports')->nullable();
            $table->decimal('delivery_ratio', 5, 4)->nullable();
            $table->decimal('cancellation_ratio', 5, 4)->nullable();
            $table->json('raw')->nullable();
            $table->string('source', 32)->default('official_api');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['phone', 'fetched_at']);
            $table->index(['provider_id', 'phone', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_fraud_lookups');
    }
};
