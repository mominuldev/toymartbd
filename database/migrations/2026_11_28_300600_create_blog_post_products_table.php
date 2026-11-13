<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products linked from a post (Phase 7 blog). `section_id` set: a buying-guide pick in that
 * section; null: the post's manual "related products". Picks of a product that is out of
 * stock, archived or unpublished are hidden on read, never deleted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('blog_post_sections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('note', 500)->nullable();
            $table->string('note_bn', 500)->nullable();

            $table->index(['post_id', 'section_id', 'position']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_products');
    }
};
