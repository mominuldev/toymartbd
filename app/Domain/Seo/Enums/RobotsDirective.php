<?php

declare(strict_types=1);

namespace App\Domain\Seo\Enums;

/** The robots meta values the admin may choose (SeoPanel "Advanced"). */
enum RobotsDirective: string
{
    case IndexFollow = 'index,follow';
    case NoindexFollow = 'noindex,follow';
    case IndexNofollow = 'index,nofollow';
    case NoindexNofollow = 'noindex,nofollow';

    public function indexable(): bool
    {
        return $this === self::IndexFollow || $this === self::IndexNofollow;
    }
}
