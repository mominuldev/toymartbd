<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 (as built, P4 batch 5) — where a product is sold. Four stored
 * flags edited in the product form's "Status & visibility" card (Figma 241:37942).
 * Additive with defaults, so existing rows keep selling online and at POS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('channel_online_store')->default(true)->after('is_featured');
            $table->boolean('channel_pos')->default(true)->after('channel_online_store');
            $table->boolean('channel_social')->default(false)->after('channel_pos');
            $table->boolean('channel_google_merchant')->default(false)->after('channel_social');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['channel_online_store', 'channel_pos', 'channel_social', 'channel_google_merchant']);
        });
    }
};
