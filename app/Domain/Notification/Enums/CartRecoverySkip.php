<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/** Why an abandoned cart was not messaged (`cart_recoveries.skip_reason`, P7). */
enum CartRecoverySkip: string
{
    case NoPhone = 'no_phone';
    /** No opt-in: a guest did not tick the reminder box, or a customer never opted in to marketing SMS. */
    case NoConsent = 'no_consent';
    /** The customer explicitly turned marketing SMS off. */
    case OptedOut = 'opted_out';
    /** This phone was reminded within the cooldown window. */
    case Cooldown = 'cooldown';
    /** The phone placed an order after the cart was last changed. */
    case AlreadyOrdered = 'already_ordered';
    case BelowMinimum = 'below_minimum';
    /** The `cart.recovery` SMS template is inactive. */
    case TemplateInactive = 'template_inactive';
    /** The cart turned into an order before the reminder went out. */
    case Converted = 'converted';
}
