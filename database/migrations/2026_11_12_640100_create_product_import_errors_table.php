<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4-CAT-10: one row per problem found in an import file — spreadsheet row number
 * (the header is row 1), the column it belongs to and the message. A malformed row is
 * always reported here, never silently skipped. Rewritten on each run (preview, then
 * commit), so it always describes the latest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('product_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->comment('0 = the file itself');
            $table->string('sku', 64)->nullable();
            $table->string('column', 64)->nullable();
            $table->string('message', 1000);

            $table->index(['import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_errors');
    }
};
