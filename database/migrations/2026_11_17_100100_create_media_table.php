<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media library (DATABASE-DESIGN §3.9 `media`, as built; docs/api/media-library.md).
 *
 * `base_path` is the directory of one immutable file set: the private master
 * (`{base}/master.webp`, or `{base}/document.pdf`) on MEDIA_ORIGINALS_DISK and, for an
 * image, the public renditions `{base}/{width}.{webp|avif}` on MEDIA_DISK. Replace writes
 * a new base under the same row. No soft delete: a delete removes the row, and the files
 * after commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16); // image | document
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('filename', 191);
            $table->string('caption', 500)->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->string('alt_text_bn', 255)->nullable();
            $table->string('base_path', 191)->unique();
            $table->string('mime', 64);          // stored: image/webp | application/pdf
            $table->string('original_mime', 64); // sniffed upload type
            $table->string('processing_status', 16)->default('ready');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('original_size_bytes')->default(0);
            $table->unsignedBigInteger('master_bytes')->default(0);
            $table->unsignedBigInteger('renditions_bytes')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0); // served: largest WebP / the PDF
            $table->json('renditions')->nullable();               // [{w, f, b}]
            $table->string('origin', 16)->default('library');     // library | product
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['folder_id', 'id']);
            $table->index(['kind', 'id']);
            $table->index('filename');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
