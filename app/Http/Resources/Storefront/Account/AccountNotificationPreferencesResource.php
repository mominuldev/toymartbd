<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The signed-in customer's own order-update switches, one row per messaging channel (WhatsApp, SMS).
 * `is_default` means the customer has not chosen yet (order updates are on by default). Security
 * messages (sign-in codes) are always on and not listed. `whatsapp_available` is false while the shop
 * sends no WhatsApp messages at all: order updates then go by SMS whatever the WhatsApp switch says.
 *
 * @property array{preferences: list<array{channel: NotificationChannel, category: NotificationCategory, enabled: bool, is_default: bool}>, whatsapp_available: bool} $resource
 */
#[SchemaName('StorefrontAccountNotificationPreferences')]
final class AccountNotificationPreferencesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var list<array{channel: 'whatsapp'|'sms', category: 'order_updates', enabled: bool, is_default: bool}> */
            'preferences' => array_map(static fn (array $row): array => [
                'channel' => $row['channel']->value,
                'category' => $row['category']->value,
                'enabled' => $row['enabled'],
                'is_default' => $row['is_default'],
            ], $this->resource['preferences']),
            /** @var bool */
            'whatsapp_available' => $this->resource['whatsapp_available'],
        ];
    }
}
