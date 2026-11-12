<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One cell of a customer's preference matrix (P4-NOT-03). `is_default` means no explicit
 * choice is stored; `can_opt_out` is false for `security` (OTP), which is always on.
 *
 * @property array{channel: NotificationChannel, category: NotificationCategory, enabled: bool, is_default: bool, can_opt_out: bool} $resource
 */
#[SchemaName('NotificationPreference')]
final class NotificationPreferenceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'sms'|'whatsapp'|'email' */
            'channel' => $this->resource['channel']->value,
            /** @var 'security'|'order_updates'|'marketing' */
            'category' => $this->resource['category']->value,
            'enabled' => $this->resource['enabled'],
            'is_default' => $this->resource['is_default'],
            'can_opt_out' => $this->resource['can_opt_out'],
        ];
    }
}
