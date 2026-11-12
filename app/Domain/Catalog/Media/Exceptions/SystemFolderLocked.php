<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Renaming or deleting a system media folder. Rendered as 409 `conflict`. */
final class SystemFolderLocked extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct((string) __('media.folder.system_locked'));
    }
}
