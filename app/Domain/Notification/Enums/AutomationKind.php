<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * The scheduled message automations (P8, roadmap Phase 8 "Notification automation"), each
 * bound to one template key in the library and logged once per subject and period in
 * `automation_sends`.
 */
enum AutomationKind: string
{
    /** N days after delivery, once per order (subject = order, period ''). */
    case ReviewRequest = 'review_request';
    /** On the customer's birthday, 10:00 Dhaka, once per year (subject = customer, period = year). */
    case Birthday = 'birthday';

    public function template(): string
    {
        return match ($this) {
            self::ReviewRequest => 'review.request',
            self::Birthday => 'customer.birthday',
        };
    }
}
