<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification templates (P4-NOT-01..03, P4-I18N-03). One row per (key, channel), with
 * English and Bangla bodies. The variables a body may use are an allowlist in code
 * (App\Domain\Notification\Support\TemplateCatalog), not here. `chars_*`, `segments_*`
 * and `encoding_*` are computed server-side from the rendered sample on every save, so
 * the cost of a Bangla SMS (UCS-2, 70/67 a segment) is visible next to its English one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->string('channel', 16);
            $table->string('category', 32);
            $table->string('subject_en', 150)->nullable();
            $table->string('subject_bn', 150)->nullable();
            $table->string('preheader_en', 200)->nullable();
            $table->string('preheader_bn', 200)->nullable();
            $table->text('body_en');
            $table->text('body_bn');
            // The Meta-approved template name for WhatsApp; null for SMS and email.
            $table->string('provider_template', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('chars_en')->nullable();
            $table->unsignedSmallInteger('chars_bn')->nullable();
            $table->unsignedTinyInteger('segments_en')->nullable();
            $table->unsignedTinyInteger('segments_bn')->nullable();
            $table->string('encoding_en', 8)->nullable();
            $table->string('encoding_bn', 8)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'channel']);
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
