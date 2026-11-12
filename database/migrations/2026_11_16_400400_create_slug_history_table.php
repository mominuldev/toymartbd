<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.9 — `slug_history`: every change to a published slug, so the old
 * URL can 301 to the current one. Written by App\Domain\Seo\Actions\SaveProductWithSeo
 * in the product's own transaction. The redirect middleware that reads it is Phase 7
 * (SEO); until then the rows are recorded, not served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_history', function (Blueprint $table): void {
            $table->id();
            $table->string('sluggable_type', 64);
            $table->unsignedBigInteger('sluggable_id');
            $table->string('old_slug', 191);
            $table->string('new_slug', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['sluggable_type', 'old_slug']);
            $table->index(['sluggable_type', 'sluggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_history');
    }
};
