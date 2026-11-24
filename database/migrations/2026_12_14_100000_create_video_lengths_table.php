<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The detected length of each video a reel links to (VideoLengths, FetchVideoLength), keyed by the
 * link. Kept outside block content so published versions stay frozen: the storefront shows the
 * editor's typed length, else this one. A new table: no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_lengths', function (Blueprint $table): void {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->string('url', 2048);
            $table->string('provider', 16);
            $table->unsignedInteger('seconds')->nullable();
            $table->string('status', 16)->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_lengths');
    }
};
