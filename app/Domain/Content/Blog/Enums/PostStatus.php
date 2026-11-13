<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Enums;

/**
 * A post's editorial state. Live on the storefront = not draft and `published_at` reached:
 * a scheduled post goes live at its instant even before the sweep flips it to `published`.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
}
