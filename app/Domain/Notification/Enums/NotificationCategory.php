<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * What a message is for, which decides whether a customer may opt out of it.
 *
 * - **security** — OTP and login codes. Never opt-out-able: refusing them would lock the
 *   customer out, and they are only ever sent because the customer just asked for one.
 * - **order_updates** — transactional messages about an order the customer placed.
 *   On by default; the customer may turn off a channel (e.g. no SMS, WhatsApp only).
 * - **marketing** — campaigns, cart reminders, review requests. Off until opted in.
 */
enum NotificationCategory: string
{
    case Security = 'security';
    case OrderUpdates = 'order_updates';
    case Marketing = 'marketing';

    public function canOptOut(): bool
    {
        return $this !== self::Security;
    }

    public function enabledByDefault(): bool
    {
        return $this !== self::Marketing;
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return [self::OrderUpdates, self::Marketing];
    }
}
