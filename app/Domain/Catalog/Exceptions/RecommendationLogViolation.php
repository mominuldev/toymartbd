<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use LogicException;

/** A programming error against `recommendation_events` — never a customer-facing condition. */
final class RecommendationLogViolation extends LogicException
{
    public static function appendOnly(): self
    {
        return new self('recommendation_events is append-only: an event is never updated or deleted (rule 25d).');
    }
}
