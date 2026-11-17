<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AI call (owner request 2026-11-17): who, which feature, provider and model, tokens
 * and the estimated cost that the monthly cap is checked against. Append-only, like the ledgers
 * (rule 8). No prompt, no answer and no key is stored — only the counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('feature', 30);
            $table->string('provider', 20);
            $table->string('model', 60);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->string('reason', 30)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
