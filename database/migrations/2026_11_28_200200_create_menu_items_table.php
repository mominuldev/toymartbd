<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One menu entry. `link_type` category|brand|page|post|product|custom|category_tree|none:
 * an entity link stores its id (`link_id`) and the URL is resolved on read, so a renamed
 * slug never leaves a dead link; `custom` stores `url`; `category_tree` expands to the
 * category mega menu; `none` is a heading (a footer column title). Nesting depth is capped
 * per location by the application. A save replaces the menu's items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('label', 80);
            $table->string('label_bn', 80)->nullable();
            $table->string('link_type', 16);
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('url', 2048)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamps();
            $table->index(['menu_id', 'parent_id', 'position']);
            $table->index(['link_type', 'link_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
