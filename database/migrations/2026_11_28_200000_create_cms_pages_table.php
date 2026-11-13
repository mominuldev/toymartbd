<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.9 `cms_pages` (Phase 7): static pages — About, Contact, Delivery policy,
 * Returns policy, Privacy, Terms, FAQ. Bangla and English title/body (`content*` is sanitised
 * HTML), one ASCII slug shared by both locales (rule 37). `status` draft|published|archived;
 * a published page with a future `published_at` is scheduled. `faq_items` holds the FAQ
 * template's question/answer pairs (JSON: genuinely structured, read by the FAQPage schema).
 * SEO lives in `seo_meta` (seoable_type `page`), retired slugs in `slug_history`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 94)->unique();
            $table->string('title', 191);
            $table->string('title_bn', 191)->nullable();
            $table->longText('content')->nullable();
            $table->longText('content_bn')->nullable();
            $table->json('faq_items')->nullable();
            $table->string('template', 16)->default('default')->comment('default|sidebar|faq');
            $table->string('status', 16)->default('draft')->comment('draft|published|archived');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
