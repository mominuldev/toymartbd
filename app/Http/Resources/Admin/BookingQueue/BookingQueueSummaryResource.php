<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\BookingQueue;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * `GET /shipments/booking-queue` — the B · 07 header facts, courier cards and tab counts
 * (docs/api/booking-queue.md). Every figure is read from stored data; nothing is estimated.
 * `balance` is the courier's own balance as last stored on `courier_providers` (null = never
 * checked — show nothing, never ৳0).
 *
 * @property array{production: bool, cutoff_time: string, today: string, couriers: list<array<string, mixed>>, counts: array{ready: int, submitted: int, booked_today: int, failed: int, unmapped: int}} $resource
 */
#[SchemaName('BookingQueueSummary')]
final class BookingQueueSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var bool Production: an unverified courier cannot book (rule 33a). */
            'production' => $r['production'],
            /** @var string Today's pickup cut-off, "HH:MM" Asia/Dhaka (Settings → Delivery areas). */
            'cutoff_time' => $r['cutoff_time'],
            /** @var string Today's Dhaka date (Y-m-d) — what "booked today" counts (rule 11). */
            'today' => $r['today'],
            /** @var list<array{id: int, code: string, name: string, name_bn: string|null, is_active: bool, is_default: bool, requires_location_mapping: bool, supports_bulk: bool, trust: 'verified'|'unverified'|'not_connected', blocked_reason: 'inactive'|'not_connected'|'unverified_in_production'|null, bookings_count: int, booked_today: int, awaiting_id: int, webhook: array{supported: bool, health: 'unsupported'|'none'|'healthy'|'failing', last_received_at: string|null}, balance: string|null, balance_checked_at: string|null}> */
            'couriers' => array_map(static function (array $row): array {
                /** @var CourierProvider $p */
                $p = $row['provider'];

                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'name_bn' => $p->name_bn,
                    'is_active' => $p->is_active,
                    'is_default' => $p->is_default,
                    'requires_location_mapping' => $p->requires_location_mapping,
                    'supports_bulk' => $p->supports_bulk,
                    'trust' => $row['trust'],
                    'blocked_reason' => $row['blocked_reason'],
                    'bookings_count' => $row['bookings_count'],
                    'booked_today' => $row['booked_today'],
                    'awaiting_id' => $row['awaiting_id'],
                    'webhook' => $row['webhook'],
                    'balance' => $p->balance?->toDecimal(),
                    'balance_checked_at' => $p->balance_checked_at?->toIso8601String(),
                ];
            }, $r['couriers']),
            /** @var array{ready: int, submitted: int, booked_today: int, failed: int, unmapped: int} */
            'counts' => $r['counts'],
        ];
    }
}
