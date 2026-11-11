<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `products` (P4-CAT-01). Prices, SKU and stock live on the
 * variant, never here (§1.1): every product has at least one `product_variants` row.
 *
 * Beyond the doc's column list: the Bangla copy columns every customer-facing
 * catalog table carries (rule 35) — `short_description_bn`, `description_bn`,
 * `safety_note_bn`.
 *
 * `slug` ≤ 92 so the revalidation tag `product:{slug}` fits the receiver's
 * 100-character limit (docs/api/revalidation.md).
 *
 * Age is in months (0–36 is where parents shop most precisely). Indexes per §6;
 * the FULLTEXT `(name)` Meilisearch fallback is MySQL-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('name_bn', 160)->nullable();
            $table->string('slug', 92)->unique();
            $table->string('type', 16)->comment('simple|variable');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('primary_category_id')->constrained('categories')->restrictOnDelete();
            $table->text('short_description')->nullable();
            $table->text('short_description_bn')->nullable();
            $table->longText('description')->nullable();
            $table->longText('description_bn')->nullable();
            $table->json('specifications')->nullable();
            $table->unsignedSmallInteger('age_min_months')->nullable();
            $table->unsignedSmallInteger('age_max_months')->nullable();
            $table->string('gender', 16)->nullable()->comment('boys|girls|unisex');
            $table->boolean('is_educational')->default(false);
            $table->string('safety_note', 500)->nullable();
            $table->string('safety_note_bn', 500)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('status', 16)->default('draft')->comment('draft|active|archived');
            $table->boolean('is_featured')->default(false);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['primary_category_id', 'status']);
            $table->index(['is_featured', 'status']);

            if (DB::connection()->getDriverName() === 'mysql') {
                $table->fullText('name');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
