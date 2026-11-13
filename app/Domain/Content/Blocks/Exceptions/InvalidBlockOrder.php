<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Exceptions;

use DomainException;

/** A reorder must list every block of the placement exactly once. */
final class InvalidBlockOrder extends DomainException
{
    public function __construct()
    {
        parent::__construct('The order must list every block of the page exactly once.');
    }
}
