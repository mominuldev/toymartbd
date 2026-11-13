<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media referenced by CMS blocks (hero slides, promo split images) — a projection of the
 * blocks' JSON `content`, rewritten by the block Actions on every draft save (`layer = draft`)
 * and on publish / revert (`layer = live`, the newest published version only). It lets the
 * Media Library answer "where used" and refuse to delete an image a block still shows without
 * parsing JSON in SQL. Rebuildable: `CmsBlockMedia::syncDraft()` / `syncLive()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_block_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('placement', 32);
            $table->string('layer', 8)->comment('draft | live');
            $table->uuid('block_uuid');
            $table->string('block_type', 32);
            $table->string('block_label', 191)->nullable();
            $table->timestamps();

            $table->unique(['media_id', 'placement', 'layer', 'block_uuid'], 'cms_block_media_unique');
            $table->index(['placement', 'layer'], 'cms_block_media_placement_layer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_block_media');
    }
};
