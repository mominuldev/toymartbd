<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.9 — `seo_meta`: per-page search and social overrides, polymorphic
 * (products first; categories, brands and CMS pages later). Every column is optional:
 * an empty title or description falls back to the server's default for the page.
 * As built: `sitemap_exclude` added for the SeoPanel "Advanced" tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type', 64);
            $table->unsignedBigInteger('seoable_id');
            $table->string('title', 191)->nullable();
            $table->string('title_bn', 191)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('description_bn', 500)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('robots', 32)->default('index,follow');
            $table->boolean('sitemap_exclude')->default(false);
            $table->string('og_title', 191)->nullable();
            $table->string('og_description', 500)->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->string('twitter_card', 32)->default('summary_large_image');
            $table->json('schema_override')->nullable();
            $table->string('focus_keyword', 120)->nullable();
            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
    }
};
