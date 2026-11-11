<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 `categories` (P4-CAT-01): a nested set via kalnoy/nestedset,
 * because the mega menu and breadcrumbs read the tree on nearly every page and
 * writes are rare.
 *
 * The nested-set columns are declared by hand rather than with
 * `NestedSet::columns()`: that helper makes `parent_id` an `unsignedInteger`, which
 * cannot carry a MySQL foreign key to the `bigint unsigned` `categories.id`.
 *
 * `depth` is not stored: kalnoy computes it on read (`withDepth()`), and a stored
 * copy would have to be rewritten for a whole subtree on every move. See the note
 * in DATABASE-DESIGN §3.1.
 *
 * Archived (`status = archived`), never hard-deleted — products hold
 * `primary_category_id`. `deleted_at` exists per the doc but no use case writes it.
 * `slug` ≤ 91 (not 94 as for brands) so the revalidation tag `category:{slug}` fits the receiver's
 * 100-character limit (docs/api/revalidation.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->unsignedInteger('_lft')->default(0);
            $table->unsignedInteger('_rgt')->default(0);
            $table->string('name', 120);
            $table->string('name_bn', 120)->nullable();
            $table->string('slug', 91)->unique();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();
            $table->string('image_path')->nullable();
            $table->string('icon', 64)->nullable()->comment('Phosphor icon name, e.g. "puzzle-piece"');
            $table->string('banner_path')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('show_in_menu')->default(true);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['_lft', '_rgt', 'parent_id']);
            $table->index(['status', 'show_in_menu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
