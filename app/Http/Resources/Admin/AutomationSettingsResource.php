<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Settings → Automations scheduling (P8) plus what each automation did in the last 30 days
 * (counts from `automation_sends`, so "consent respected" is visible).
 *
 * @property array{settings: array{review_delay_days: int, quiet_from: string, quiet_to: string, birthday_send_at: string, birthday_lead_days: int, birthday_offer: array{enabled: bool, type: 'percent'|'fixed', value: string, max_discount: string|null, min_order_amount: string, valid_days: int, is_placeholder: bool}}, stats: array<string, array{queued: int, skipped: int, skip_reasons: array<string, int>}>} $resource
 */
#[SchemaName('AutomationSettings')]
final class AutomationSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource['settings'];

        return [
            /** @var int */
            'review_delay_days' => $s['review_delay_days'],
            /**
             * Dhaka, HH:MM.
             *
             * @var string
             */
            'quiet_from' => $s['quiet_from'],
            /**
             * Dhaka, HH:MM.
             *
             * @var string
             */
            'quiet_to' => $s['quiet_to'],
            /**
             * Fixed: birthday messages go out at this Dhaka time.
             *
             * @var string
             */
            'birthday_send_at' => $s['birthday_send_at'],
            /**
             * Days before each child's birthday the parent is reminded (0 = on the day).
             * Default 7 is a PLACEHOLDER pending the owner.
             *
             * @var int
             */
            'birthday_lead_days' => $s['birthday_lead_days'],
            /**
             * The personal single-use coupon in each reminder. `is_placeholder` = never saved: the
             * values are suggestions, and nothing is minted until the offer is saved as on.
             *
             * @var array{enabled: bool, type: 'percent'|'fixed', value: string, max_discount: string|null, min_order_amount: string, valid_days: int, is_placeholder: bool}
             */
            'birthday_offer' => $s['birthday_offer'],
            /**
             * Last 30 days, per automation (`review_request`, `birthday`).
             *
             * @var array<string, array{queued: int, skipped: int, skip_reasons: array<string, int>}>
             */
            'stats' => $this->resource['stats'],
        ];
    }
}
