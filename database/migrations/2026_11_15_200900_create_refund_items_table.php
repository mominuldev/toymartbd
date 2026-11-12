<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE-DESIGN §3.5 `refund_items` (P4-PAY-01): which lines a refund covers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 14, 2);

            $table->unique(['refund_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
    }
};
