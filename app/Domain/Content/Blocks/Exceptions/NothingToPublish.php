<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Exceptions;

use DomainException;

/** The draft is identical to the live version; publishing would only add a duplicate. */
final class NothingToPublish extends DomainException
{
    public function __construct()
    {
        parent::__construct('The draft has no unpublished changes.');
    }
}
