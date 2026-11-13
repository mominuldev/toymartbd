<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer testimonials (Phase 7), read by the homepage testimonials block. Quote, name and
 * location in both locales; `rating` 1–5 or null; `media_id` an optional photo from the media
 * library; `position` the display order among published rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_bn', 80)->nullable();
            $table->string('location', 80)->nullable();
            $table->string('location_bn', 80)->nullable();
            $table->string('quote', 600);
            $table->string('quote_bn', 600)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['is_published', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
