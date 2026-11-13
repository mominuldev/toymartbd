<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/** `cms_pages.status`. A published page with a future `published_at` is scheduled. */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
