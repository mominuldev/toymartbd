<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exceptions;

use DomainException;

/** A library rule a request broke (P7 Message templates); the API answers 409. */
final class TemplateLibraryViolation extends DomainException
{
    public static function systemTemplate(): self
    {
        return new self('System templates are bound to an order event: they cannot be archived, deleted or re-keyed. Switch one off instead.');
    }

    public static function archived(): self
    {
        return new self('This template is archived. Restore it from the archive before editing.');
    }

    public static function notWhatsApp(): self
    {
        return new self('Only WhatsApp templates have a Meta approval status.');
    }
}
