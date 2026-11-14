<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parked POS carts ("Hold sale", Figma B · 33). A held cart is not an order: it reserves no
 * stock and carries no prices — only variant ids and quantities (`lines`, {variant_id: qty}),
 * the coupon code and the customer's phone (E.164). Resuming re-prices it from current data.
 * `resumed_at` is set once; a resumed or discarded hold is history, never reopened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_held_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->string('label', 80)->nullable();
            $table->json('lines');
            $table->string('coupon_code', 64)->nullable();
            $table->string('customer_phone', 16)->nullable()->comment('E.164');
            $table->foreignId('held_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['session_id', 'resumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_held_sales');
    }
};
