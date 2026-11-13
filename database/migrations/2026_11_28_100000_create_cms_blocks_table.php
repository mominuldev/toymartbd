<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 CMS blocks — the DRAFT of a placement's block list (DATABASE-DESIGN `cms_blocks`,
 * extended for versions, per-locale/device visibility and scheduling). The live page is never
 * read from here: publishing snapshots these rows into an immutable `cms_layout_versions` row.
 *
 * `uuid` is the block's identity across versions (a duplicate gets a new one). `content` is the
 * per-type JSON validated server-side (App\Domain\Content\Blocks\Schemas). `starts_at` / `ends_at`
 * are UTC instants; the admin enters Dhaka time. Hard-deleted: history lives in the versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('placement', 24)->default('home')->comment('BlockPlacement');
            $table->string('type', 32)->comment('BlockType');
            $table->string('label', 120)->nullable()->comment('Internal name in the builder, never shown to shoppers');
            $table->json('content');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('show_en')->default(true);
            $table->boolean('show_bn')->default(true);
            $table->boolean('show_mobile')->default(true);
            $table->boolean('show_desktop')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_blocks');
    }
};
