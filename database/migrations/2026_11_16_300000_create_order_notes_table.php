<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `order_notes` (P4-ORD-05) — staff notes on an order, append-only like `order_events`:
 * a note is never edited or deleted, a correction is a new note. Kept apart from the
 * status timeline so a long note never has to fit `order_events.description` (500) and
 * the board's timeline stays one row per state change. `is_customer_visible` defaults
 * to private; the tracking page may show only the visible ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_customer_visible')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
    }
};
