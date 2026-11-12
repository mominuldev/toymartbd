<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.4 `order_events` (P4-ORD-01) — the order's timeline, append-only.
 * One table serves the internal activity log and the customer tracking timeline;
 * `is_customer_visible` decides which rows the storefront may show. Every state-machine
 * transition writes exactly one row (P4-ORD-03). `meta` never holds a full phone or
 * address (rule 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 32)->comment('OrderEventType');
            $table->string('field', 24)->nullable()->comment('status|payment_status|fulfillment_status');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('description', 500)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_customer_visible')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
