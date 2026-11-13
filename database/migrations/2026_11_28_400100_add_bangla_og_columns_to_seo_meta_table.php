<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 per-locale SEO (P-06c): a separate Open Graph title and description for the
 * Bangla page. Expand only — nullable, an empty value falls back to the Bangla meta title.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table): void {
            $table->string('og_title_bn', 191)->nullable()->after('og_description');
            $table->string('og_description_bn', 500)->nullable()->after('og_title_bn');
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table): void {
            $table->dropColumn(['og_title_bn', 'og_description_bn']);
        });
    }
};
