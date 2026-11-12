<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Notification spend and health for the current Dhaka month (P4-NOT-01): per channel,
 * messages sent, segments and total cost (decimal strings), plus the dead-letter count
 * the admin must act on. Summed server-side (rule 1).
 *
 * @property array{month: string, dead_lettered: int, channels: list<array{channel: string, sent: int, segments: int, cost: string}>, total_cost: string} $resource
 */
#[SchemaName('NotificationSummary')]
final class NotificationSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string Dhaka month, Y-m */
            'month' => $this->resource['month'],
            /** @var int */
            'dead_lettered' => $this->resource['dead_lettered'],
            /** @var list<array{channel: 'sms'|'whatsapp'|'email', sent: int, segments: int, cost: string}> */
            'channels' => $this->resource['channels'],
            /** @var string */
            'total_cost' => $this->resource['total_cost'],
        ];
    }
}
