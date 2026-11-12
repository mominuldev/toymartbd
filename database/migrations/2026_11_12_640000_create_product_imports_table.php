<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4-CAT-10 bulk product import. One row per uploaded file. The file is validated
 * in full first (a dry run: every row through the product FormRequest and the
 * Create/UpdateProduct actions, rolled back), and committed only when staff confirm
 * the preview. `status`: queued → previewing → previewed → importing → completed |
 * failed. Counts are what the latest run found; the row-level problems live in
 * `product_import_errors`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('path', 255)->comment('on the local (private) disk');
            $table->string('format', 8)->comment('csv|xlsx');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('create_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};
