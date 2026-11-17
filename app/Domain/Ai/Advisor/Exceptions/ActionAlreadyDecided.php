<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Exceptions;

use RuntimeException;

/** A suggested action was already marked done or not useful; the HTTP layer answers 409. */
final class ActionAlreadyDecided extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This suggestion was already marked done or not useful.');
    }
}
