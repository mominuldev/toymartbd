<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `recommendation_events` (P10, rule 25d): one row per recommendation
 * impression, click, add-to-cart (from the storefront, through a queued job) and purchase
 * (written by the server when an order is placed). Append-only — the model refuses updates
 * and deletes.
 *
 * No foreign keys, on purpose: this is a high-volume measurement log written in bulk from a
 * queue, and a cascade from a product or order would have to delete history it may never
 * delete. Ids are plain references; reports join to whatever still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('placement', 32)->comment('RecommendationPlacement value');
            $table->string('event', 16)->comment('impression|click|add_to_cart|purchased');
            $table->unsignedBigInteger('source_product_id')->nullable()->comment('null for cart/confirmation placements');
            $table->unsignedBigInteger('recommended_product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('session_id', 64)->nullable()->comment('opaque storefront session id (tm_rsid cookie)');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->comment('purchased events only');
            $table->dateTime('occurred_at')->comment('UTC');

            $table->index(['placement', 'occurred_at']);
            $table->index(['source_product_id', 'recommended_product_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_events');
    }
};
