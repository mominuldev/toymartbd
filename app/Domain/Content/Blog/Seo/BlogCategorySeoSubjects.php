<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Seo;

use App\Domain\Content\Blog\Models\BlogCategory;

/** Blog category archives (`/blog/category/{slug}`) as SEO subjects. */
final class BlogCategorySeoSubjects extends BlogTermSeoSubjects
{
    protected function model(): string
    {
        return BlogCategory::class;
    }

    protected function prefix(): string
    {
        return '/blog/category/';
    }
}
