<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buying-guide sections (Phase 7 blog): a heading, sanitised intro HTML, an optional age band
 * and budget (DECIMAL(14,2), rule 6), and its product picks (blog_post_products.section_id).
 * Replaced wholesale on every save of the post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('heading', 191);
            $table->string('heading_bn', 191)->nullable();
            $table->text('body')->nullable();
            $table->text('body_bn')->nullable();
            $table->string('age_band', 16)->nullable();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->timestamps();

            $table->index(['post_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_sections');
    }
};
