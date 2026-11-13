<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-cart recovery (P7, docs/api/cart-recovery.md). One row per abandoned cart —
 * UNIQUE `cart_id`, so a cart is messaged at most once — recording what was decided
 * (`sent` / `skipped` with a reason), the hashed restore token the link carries (the
 * link holds no PII and not the cart token), and the order the cart became, if any.
 * The message itself (status, segments, cost) is the `notification_logs` row
 * (`notifiable_type = cart_recovery`). `phone_hash` enforces the per-phone cooldown
 * without storing the number twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->unique()->constrained('carts')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('phone_hash', 64)->nullable();
            $table->string('status', 20);
            $table->string('skip_reason', 40)->nullable();
            $table->string('locale', 2)->default('bn');
            $table->decimal('cart_value', 14, 2)->default('0.00');
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('abandoned_at');
            $table->string('restore_token_hash', 64)->nullable()->unique();
            $table->timestamp('restore_expires_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('recovered_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();

            $table->index(['phone_hash', 'created_at']);
            $table->index(['status', 'abandoned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_recoveries');
    }
};
