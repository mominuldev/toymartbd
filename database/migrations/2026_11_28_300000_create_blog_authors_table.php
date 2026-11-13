<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog authors (Phase 7 blog, DATABASE-DESIGN §3.9 as built). A byline, not a login: an
 * author may be linked to a staff user but need not be. The slug is ASCII and shared by
 * both locales (rule 37); the avatar is a media-library image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('name_bn', 120)->nullable();
            $table->string('slug', 94)->unique();
            $table->text('bio')->nullable();
            $table->text('bio_bn')->nullable();
            $table->foreignId('avatar_media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_authors');
    }
};
