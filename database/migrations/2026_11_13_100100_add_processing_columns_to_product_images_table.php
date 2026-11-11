<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendition state for the media pipeline (P4-CAT-07): `processing` until the queued
 * job has written every rendition, then `ready` with the widths it produced (a
 * small source stops below the configured maximum). `failed` keeps the row so the
 * admin can see and delete it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('processing_status', 16)->default('ready')->after('height');
            $table->json('rendition_widths')->nullable()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn(['processing_status', 'rendition_widths']);
        });
    }
};
