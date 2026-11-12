<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which order lines a return covers, and the inspection result per line. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_resellable')->nullable()->comment('set at inspection');
            $table->unsignedInteger('quantity_damaged')->nullable()->comment('set at inspection');

            $table->unique(['order_return_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
    }
};
