<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Seo;

use App\Domain\Content\Blog\Models\BlogTag;

/** Blog tag archives (`/blog/tag/{slug}`) as SEO subjects. */
final class BlogTagSeoSubjects extends BlogTermSeoSubjects
{
    protected function model(): string
    {
        return BlogTag::class;
    }

    protected function prefix(): string
    {
        return '/blog/tag/';
    }
}
