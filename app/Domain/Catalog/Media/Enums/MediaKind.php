<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Enums;

/** What a media row holds (docs/api/media-library.md, decision 4: no video in v1). */
enum MediaKind: string
{
    case Image = 'image';
    case Document = 'document';
}
