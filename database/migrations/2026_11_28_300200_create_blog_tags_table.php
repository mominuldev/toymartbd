<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog tags (Phase 7 blog). Named `blog_tags`, not the `tags` of the DATABASE-DESIGN sketch:
 * a bare `tags` table would collide with any later tagging of products or customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_bn', 80)->nullable();
            $table->string('slug', 94)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_tags');
    }
};
