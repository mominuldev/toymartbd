<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The category's image, picked from the Media Library (the home category tile art, Smart Junior
 * docs/_design/07 §13: a transparent cut-out). Additive and nullable; the legacy `image_path`
 * stays as the fallback. Restrict on delete: the library refuses to delete an image in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('image_media_id')->nullable()->after('image_path')->constrained('media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('image_media_id');
        });
    }
};
