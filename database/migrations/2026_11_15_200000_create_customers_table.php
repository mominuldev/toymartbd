<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.3 `customers` (P4-ORD-01). Phone is the identity (§1.4): stored
 * E.164 (`+8801XXXXXXXXX`, rule 30) and UNIQUE, so a guest order auto-attaches to the
 * customer by phone. `user_id` stays null until a storefront account exists (P4-STO-11),
 * so it is a plain nullable id, not a FK to the staff `users` table.
 *
 * The aggregate columns (`orders_count` … `last_order_at`) are a projection of `orders`
 * (P4-ORD-10), rebuildable with `toymart:customers:rebuild-aggregates`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('phone', 16)->unique()->comment('E.164');
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('email', 191)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('preferred_language', 2)->default('bn')->comment('bn|en');
            $table->string('source', 16)->default('web')->comment('CustomerSource');
            $table->json('tags')->nullable();
            $table->string('status', 16)->default('active')->comment('CustomerStatus');

            // Projection of orders (P4-ORD-10).
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('delivered_orders_count')->default(0);
            $table->decimal('total_spent', 14, 2)->default('0.00');
            $table->decimal('avg_order_value', 14, 2)->default('0.00');
            $table->timestamp('aggregates_refreshed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('total_spent');
            $table->index('last_order_at');
            $table->index('orders_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
