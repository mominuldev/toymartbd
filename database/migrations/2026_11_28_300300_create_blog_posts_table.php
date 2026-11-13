<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog posts and buying guides (Phase 7 blog; DATABASE-DESIGN §3.9 `blog_posts`, as built).
 *
 * - `type`: `article` | `buying_guide`; a guide also has structured sections (blog_post_sections).
 * - `status`: `draft` | `scheduled` | `published`. `published_at` (UTC) is the go-live instant;
 *   a post is live when status ≠ draft and `published_at` ≤ now. The scheduler sweep flips
 *   `scheduled` → `published` and purges the storefront cache at that moment.
 * - `body` / `body_bn` hold sanitised HTML; `reading_minutes(_bn)` are computed server-side.
 * - Guide budget is DECIMAL(14,2) (rule 6). Never deleted while published; drafts may be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16)->default('article');
            $table->string('slug', 94)->unique();
            $table->string('title', 191);
            $table->string('title_bn', 191)->nullable();
            $table->string('excerpt', 500)->nullable();
            $table->string('excerpt_bn', 500)->nullable();
            $table->longText('body')->nullable();
            $table->longText('body_bn')->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('blog_authors')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->restrictOnDelete();
            $table->unsignedSmallInteger('reading_minutes')->default(1);
            $table->unsignedSmallInteger('reading_minutes_bn')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('guide_age_band', 16)->nullable();
            $table->decimal('guide_budget_min', 14, 2)->nullable();
            $table->decimal('guide_budget_max', 14, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['type', 'status', 'published_at']);
            $table->index(['category_id', 'published_at']);
            $table->index(['author_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
