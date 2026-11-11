<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `product_relations` (P4-CAT-01): hand-curated and
 * rule-generated pairs (related, upsell, cross_sell, bundle, age_progression).
 *
 * `rule_id` is a plain nullable column for now: `product_relation_rules` is P2, and
 * its FK is added with that table. `is_auto` separates generated rows from human
 * decisions so a rule regeneration never destroys curation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 24)->comment('related|upsell|cross_sell|bundle|age_progression');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('reason_note', 500)->nullable();
            $table->boolean('is_auto')->default(false);
            $table->unsignedBigInteger('rule_id')->nullable()->comment('FK to product_relation_rules once that P2 table exists');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id', 'type']);
            $table->index(['product_id', 'type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_relations');
    }
};
