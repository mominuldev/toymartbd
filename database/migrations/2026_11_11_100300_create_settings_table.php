<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.10 — typed key/value settings, grouped.
 *
 * `value` is JSON so a setting keeps its type (string, int, bool, list, map). When
 * `is_encrypted` is true the JSON holds a Laravel-encrypted string (credentials, rule 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 191)->primary();
            $table->json('value')->nullable();
            $table->string('group', 50)->index();
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
