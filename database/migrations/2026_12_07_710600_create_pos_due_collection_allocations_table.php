<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One slice of a due collection applied to one order (FIFO, oldest order first): the payment
 * row it wrote on that order (UNIQUE — a payment belongs to at most one collection), the
 * tender method and the amount. This is how DrawerTotals tells a sale's own tenders from due
 * collected later — possibly on another drawer — on the same order. Written once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_due_collection_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('pos_due_collections')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->string('method', 24)->comment('PaymentMethod');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_due_collection_allocations');
    }
};
