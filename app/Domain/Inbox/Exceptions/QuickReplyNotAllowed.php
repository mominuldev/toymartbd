<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A quick action whose target does not fit this conversation (an order of another customer, an unpublished product). 409. */
final class QuickReplyNotAllowed extends ConflictHttpException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
