<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a customer's opt-in / opt-out for one (channel, category) (P4-NOT-03).
 * Refuses the security category: OTP cannot be opted out of.
 */
final class SetNotificationPreference
{
    /** @param  'admin'|'storefront'  $source */
    public function handle(int $customerId, NotificationChannel $channel, NotificationCategory $category, bool $enabled, string $source, ?int $userId = null): NotificationPreference
    {
        if (! $category->canOptOut()) {
            throw new InvalidArgumentException('Security messages cannot be opted out of.');
        }

        return DB::transaction(static fn (): NotificationPreference => NotificationPreference::query()->updateOrCreate(
            ['customer_id' => $customerId, 'channel' => $channel->value, 'category' => $category->value],
            ['enabled' => $enabled, 'source' => $source, 'updated_by' => $userId],
        ));
    }
}
