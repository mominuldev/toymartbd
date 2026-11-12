<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media library folders (docs/api/media-library.md, decision 1). Flat: no parent. The four
 * system folders are inserted here, not only by the seeder, because the product-image
 * backfill (2026_11_17_100300) files every existing product photo under `products`.
 * MediaFoldersSeeder re-asserts them idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            // Lower-cased trimmed name: the case-insensitive uniqueness the API enforces.
            $table->string('name_key', 60)->unique();
            $table->string('system_key', 32)->nullable()->unique();
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();
        });

        $now = now();
        $system = [
            ['Products', 'products', 0],
            ['Category banners', 'category_banners', 1],
            ['Blog', 'blog', 2],
            ['Pages', 'pages', 3],
        ];

        foreach ($system as [$name, $key, $position]) {
            DB::table('media_folders')->insert([
                'name' => $name,
                'name_key' => mb_strtolower($name),
                'system_key' => $key,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
