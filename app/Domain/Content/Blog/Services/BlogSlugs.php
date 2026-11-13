<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Content\Blog\Models\BlogPost;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Model;

/**
 * ASCII slugs, transliterated from the English title or else the Bangla one, shared by
 * both locales (rule 37), unique per table ("lego-guide", "lego-guide-2" …).
 */
final class BlogSlugs
{
    /** @param  class-string<Model>  $model */
    public function generate(string $model, ?string $name, ?string $nameBn): string
    {
        $base = rtrim(substr(Slug::forNames($name, $nameBn), 0, BlogPost::SLUG_BASE_MAX), '-');

        return Slug::unique($base, static fn (string $candidate): bool => $model::query()->where('slug', $candidate)->exists());
    }
}
