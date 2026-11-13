<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/** `customer_message_consents.purpose` — consent beyond the channel preferences (P8). */
enum MessageConsentPurpose: string
{
    /**
     * Birthday reminders with a gift offer, before each of the customer's children's birthdays
     * (`customer_children`, P8 batch 3): opt-in only, never by default, never on checkout.
     */
    case Birthday = 'birthday';
}
