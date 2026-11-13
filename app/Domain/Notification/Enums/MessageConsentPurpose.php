<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/** `customer_message_consents.purpose` — consent beyond the channel preferences (P8). */
enum MessageConsentPurpose: string
{
    /** Birthday messages read `customers.date_of_birth`: opt-in only, never by default. */
    case Birthday = 'birthday';
}
