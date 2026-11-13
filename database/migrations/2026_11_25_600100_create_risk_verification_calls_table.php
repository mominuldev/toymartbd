<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verification queue's call log (admin checklist §2.4 "call outcome logging"): one row
 * per call a staff member made about a held order. Insert-only — the history of who called,
 * when, and what the customer said is the evidence behind a verify or a cancel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_verification_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('phone', 16)->comment('E.164 called');
            $table->string('outcome', 24)->comment('CallOutcome');
            $table->string('note', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['order_id', 'created_at']);
            $table->index(['phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_verification_calls');
    }
};
