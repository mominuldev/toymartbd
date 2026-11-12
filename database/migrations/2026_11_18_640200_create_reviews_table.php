<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `reviews` (P4-CAT-12). `is_verified_purchase` is never taken
 * from the client: it is derived from `order_item_id`, which must belong to the
 * reviewing customer's own delivered order for this product. Reviews default to
 * `pending`; only `approved` ones are public and counted in
 * `products.rating_avg` / `rating_count`.
 *
 * Beyond the doc's column list: `moderated_by` / `moderated_at` (who approved or
 * rejected, and when), `admin_reply_at`, and `locale` (the language the shopper wrote
 * in, so the storefront can label it). One review per order line: UNIQUE
 * `order_item_id` (many NULLs allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->unique()->constrained('order_items')->nullOnDelete();
            $table->string('reviewer_name', 120);
            $table->unsignedTinyInteger('rating');
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('status', 16)->default('pending')->comment('pending|approved|rejected');
            $table->string('locale', 2)->default('en');
            $table->text('admin_reply')->nullable();
            $table->timestamp('admin_reply_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
