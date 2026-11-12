<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationPreference;

/**
 * Answers "may we send this customer this kind of message on this channel?" (P4-NOT-03).
 * Security messages (OTP) always may — they cannot be opted out of. Everything else
 * follows the customer's explicit row, or the category default when there is none.
 */
final class NotificationPreferences
{
    public function allows(?int $customerId, NotificationChannel $channel, NotificationCategory $category): bool
    {
        if (! $category->canOptOut()) {
            return true;
        }

        if ($customerId === null) {
            return $category->enabledByDefault();
        }

        $row = NotificationPreference::query()
            ->where('customer_id', $customerId)
            ->where('channel', $channel->value)
            ->where('category', $category->value)
            ->first();

        return $row === null ? $category->enabledByDefault() : $row->enabled;
    }

    /**
     * The full matrix for one customer, defaults filled in.
     *
     * @return list<array{channel: NotificationChannel, category: NotificationCategory, enabled: bool, is_default: bool, can_opt_out: bool}>
     */
    public function matrix(int $customerId): array
    {
        $rows = NotificationPreference::query()->where('customer_id', $customerId)->get()
            ->keyBy(static fn (NotificationPreference $p): string => $p->channel->value.'.'.$p->category->value);

        $matrix = [];
        foreach (NotificationChannel::cases() as $channel) {
            foreach (NotificationCategory::cases() as $category) {
                $row = $rows->get($channel->value.'.'.$category->value);
                $matrix[] = [
                    'channel' => $channel,
                    'category' => $category,
                    'enabled' => ! $category->canOptOut() || ($row === null ? $category->enabledByDefault() : $row->enabled),
                    'is_default' => $row === null,
                    'can_opt_out' => $category->canOptOut(),
                ];
            }
        }

        return $matrix;
    }
}
