<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Seo;

use App\Domain\Content\Blog\Models\BlogAuthor;

/** Blog author archives (`/blog/author/{slug}`) as SEO subjects. */
final class BlogAuthorSeoSubjects extends BlogTermSeoSubjects
{
    protected function model(): string
    {
        return BlogAuthor::class;
    }

    protected function prefix(): string
    {
        return '/blog/author/';
    }
}
