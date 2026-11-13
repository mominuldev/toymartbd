<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront banners outside the homepage blocks (Phase 7): `placement`
 * announcement_bar|category_page|info_strip|popup. `starts_at`/`ends_at` are UTC instants
 * (the admin enters Dhaka time). The highest `priority` live banner of a placement wins.
 * `category_id` targets one category page (null: every category page). The info strip is
 * the only placement allowed on cart and checkout, and it is text-only (rule 25b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('placement', 24);
            $table->string('name', 120)->comment('Internal name, never shown to shoppers');
            $table->string('title', 191)->nullable();
            $table->string('title_bn', 191)->nullable();
            $table->string('body', 500)->nullable();
            $table->string('body_bn', 500)->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('link_url', 2048)->nullable();
            $table->string('link_label', 60)->nullable();
            $table->string('link_label_bn', 60)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['placement', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
