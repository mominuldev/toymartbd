<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics › Growth advisor (Figma 537:82561): one weekly brief per Dhaka week (Monday start).
 * Regenerating the week rewrites its row. The sentences are the AI's plain-words summary, already
 * checked against the facts (FigureGuard, rule 1). No customer data is stored — figures only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_briefs', function (Blueprint $table): void {
            $table->id();
            $table->date('week_start')->unique()->comment('Monday of the Dhaka week the brief covers');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('locale', 5);
            $table->string('provider', 20);
            $table->string('model', 60);
            $table->json('sentences');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete(); // null = the Monday schedule
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_briefs');
    }
};
