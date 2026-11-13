<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/** Why an automation did not message someone (`automation_sends.skip_reason`, P8). */
enum AutomationSkip: string
{
    case NoPhone = 'no_phone';
    /** Never opted in: marketing messages are off until the customer agrees (NotificationCategory). */
    case NoConsent = 'no_consent';
    /** The customer explicitly turned these messages off on every messaging channel. */
    case OptedOut = 'opted_out';
    /** Every channel's template for this automation is switched off. */
    case TemplateInactive = 'template_inactive';
    /** The customer is blocked. */
    case Blocked = 'blocked';
}
