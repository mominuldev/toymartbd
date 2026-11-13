<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message templates library (P7, owner request 2026-11-13). Additive columns only:
 *  - `kind` — system (catalog, event-bound) | marketing | manual (staff-made). Every
 *    existing row is a catalog row, so the default is `system`.
 *  - `name_en` / `name_bn` — display names for custom templates (system names are i18n).
 *  - `custom_variables` — the allowlist of a custom template, a subset of the generic set
 *    in CustomTemplateVariables. Null for system rows: their allowlist stays in code.
 *  - `whatsapp_status` — draft | submitted | approved, set by staff; null reads as draft.
 *  - `archived_at` — custom templates are archived, never deleted. System rows never are.
 *  - `created_by` — who made a custom template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->string('kind', 16)->default('system')->after('category');
            $table->string('name_en', 120)->nullable()->after('kind');
            $table->string('name_bn', 120)->nullable()->after('name_en');
            $table->json('custom_variables')->nullable()->after('name_bn');
            $table->string('whatsapp_status', 16)->nullable()->after('provider_template');
            $table->timestamp('archived_at')->nullable()->after('is_active');
            $table->foreignId('created_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();

            $table->index(['kind', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'archived_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['kind', 'name_en', 'name_bn', 'custom_variables', 'whatsapp_status', 'archived_at']);
        });
    }
};
