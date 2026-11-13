<?php

declare(strict_types=1);

namespace App\Domain\Seo\Enums;

/**
 * The known short morph keys of `seo_meta.seoable_type` / `slug_history.sluggable_type`
 * (docs/api/seo.md §1) — never a class name, so a model rename never orphans a row.
 * The SEO module itself is open: any type with a registered SeoSubjectProvider works.
 */
enum SeoableType: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case Page = 'page';
    case BlogPost = 'blog_post';
    case BlogCategory = 'blog_category';
    case BlogTag = 'blog_tag';
    case BlogAuthor = 'blog_author';

    /** Catalogue paths (the content types' paths come from their providers). */
    public function catalogPrefix(): ?string
    {
        return match ($this) {
            self::Product => '/products/',
            self::Category => '/categories/',
            self::Brand => '/brands/',
            default => null,
        };
    }
}
