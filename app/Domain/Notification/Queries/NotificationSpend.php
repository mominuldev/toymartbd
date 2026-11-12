<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Sent messages, segments and cost per channel for one Dhaka calendar month (rule 11),
 * plus the open dead-letter count. Bucketed by `sent_at` so cost lands in the month the
 * provider charged it.
 */
final class NotificationSpend
{
    /** @return array{month: string, dead_lettered: int, channels: list<array{channel: string, sent: int, segments: int, cost: string}>, total_cost: string} */
    public function forMonth(?CarbonImmutable $inMonth = null): array
    {
        $local = ($inMonth ?? CarbonImmutable::now())->setTimezone(DhakaDate::zone());
        $start = $local->startOfMonth()->utc();
        $end = $local->startOfMonth()->addMonth()->utc();

        $rows = NotificationLog::query()
            ->where('status', NotificationStatus::Sent->value)
            ->where('sent_at', '>=', $start)
            ->where('sent_at', '<', $end)
            ->groupBy('channel')
            ->selectRaw('channel, COUNT(*) as sent, COALESCE(SUM(segments), 0) as segments, COALESCE(SUM(cost), 0) as cost')
            ->get()
            ->keyBy('channel');

        $total = Money::zero();
        $channels = [];
        foreach (NotificationChannel::cases() as $channel) {
            $row = $rows->get($channel->value);
            $cost = Money::of(self::decimal($row?->getAttribute('cost')));
            $total = $total->plus($cost);
            $channels[] = [
                'channel' => $channel->value,
                'sent' => (int) ($row?->getAttribute('sent') ?? 0),
                'segments' => (int) ($row?->getAttribute('segments') ?? 0),
                'cost' => $cost->toDecimal(),
            ];
        }

        return [
            'month' => $local->format('Y-m'),
            'dead_lettered' => NotificationLog::query()->where('status', NotificationStatus::DeadLettered->value)->count(),
            'channels' => $channels,
            'total_cost' => $total->toDecimal(),
        ];
    }

    private static function decimal(mixed $value): string
    {
        if ($value instanceof Money) {
            return $value->toDecimal();
        }
        $string = is_scalar($value) ? (string) $value : '0';

        return preg_match('/^-?\d+(\.\d+)?$/', $string) === 1 ? $string : '0';
    }
}
