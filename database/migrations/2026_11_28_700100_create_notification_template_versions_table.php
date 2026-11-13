<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version history of a notification template's copy (P7 Message templates). Append-only:
 * a row is written whenever the copy changes (the first edit also records the copy it
 * replaced as version 1), never updated or deleted. Restoring an old version writes a new
 * row with `restored_from` pointing at it. Both FK targets exist before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_template_id')->constrained('notification_templates')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject_en', 150)->nullable();
            $table->string('subject_bn', 150)->nullable();
            $table->string('preheader_en', 200)->nullable();
            $table->string('preheader_bn', 200)->nullable();
            $table->text('body_en');
            $table->text('body_bn');
            $table->unsignedInteger('restored_from')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['notification_template_id', 'version'], 'ntv_template_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_template_versions');
    }
};
