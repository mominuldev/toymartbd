<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Exceptions;

use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A delete refused because the file is used somewhere (A25 rule 2). The admin
 * controller renders it as 409 `media-in-use` with the usages; anywhere else it is a
 * plain 409 `conflict`.
 */
final class MediaInUse extends ConflictHttpException
{
    /** @param list<MediaUsage> $usages */
    public function __construct(public readonly int $mediaId, public readonly array $usages)
    {
        $count = count($usages);
        parent::__construct((string) ($count === 1 ? __('media.in_use_one') : __('media.in_use', ['count' => $count])));
    }
}
