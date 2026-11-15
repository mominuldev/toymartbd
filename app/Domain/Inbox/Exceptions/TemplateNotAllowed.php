<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A template send in a conversation that is not a WhatsApp thread with a closed window. 409 `conflict`; nothing is queued. */
final class TemplateNotAllowed extends ConflictHttpException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
