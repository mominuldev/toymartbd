<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand step: a product image may reference a media row. Nullable, no default, so the
 * ALTER is an instant metadata change on MySQL 8.4 (ALGORITHM=INSTANT for the column; the
 * index and FK build online). The existing file and alt columns stay; for a linked row
 * they become a write-through mirror that is never read (docs/api/media-library.md).
 * restrictOnDelete: a media row in use can never disappear under a product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->foreignId('media_id')->nullable()->after('variant_id')->constrained('media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('media_id');
        });
    }
};
