<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published versions of a placement's block list (Phase 7). Append-only: a publish or a revert
 * writes a new row with the next `version`; rows are never updated or deleted. The live page is
 * the highest version of the placement. `blocks` is the full ordered snapshot of the draft at
 * publish time; `content_hash` lets the builder tell whether the draft has unpublished changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_layout_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('placement', 24)->default('home')->comment('BlockPlacement');
            $table->unsignedInteger('version');
            $table->json('blocks');
            $table->unsignedSmallInteger('block_count')->default(0);
            $table->char('content_hash', 64);
            $table->unsignedInteger('restored_from_version')->nullable();
            $table->string('note', 191)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['placement', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_layout_versions');
    }
};
