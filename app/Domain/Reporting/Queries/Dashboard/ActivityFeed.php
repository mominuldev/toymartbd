<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard activity feed: the newest order timeline events (`order_events`, append-only),
 * newest first. `activity_log` is migrated but nothing writes to it yet, so the order timeline
 * is the existing activity record; when the activity log is populated it can be merged in here.
 *
 * PII-safe by construction (rule 20): only structured fields leave — event type, the status it
 * moved from/to, the order number and the staff member who did it. Free text
 * (`order_events.description`, notes, meta) is never returned, because a note can hold a phone
 * number or an address. The client renders each event from translated templates.
 */
final class ActivityFeed
{
    /**
     * @return list<array{id: int, type: string, field: string|null, from: string|null, to: string|null, order_id: int, order_number: string, actor: string|null, at: CarbonImmutable}>
     */
    public function latest(int $limit = 8): array
    {
        return DB::table('order_events')
            ->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->leftJoin('users', 'users.id', '=', 'order_events.user_id')
            ->orderByDesc('order_events.id')
            ->limit($limit)
            ->get([
                'order_events.id', 'order_events.type', 'order_events.field', 'order_events.from_status', 'order_events.to_status',
                'order_events.order_id', 'order_events.created_at', 'orders.number', 'users.name as actor',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'field' => $row->field === null ? null : (string) $row->field,
                'from' => $row->from_status === null ? null : (string) $row->from_status,
                'to' => $row->to_status === null ? null : (string) $row->to_status,
                'order_id' => (int) $row->order_id,
                'order_number' => (string) $row->number,
                'actor' => $row->actor === null ? null : (string) $row->actor,
                'at' => CarbonImmutable::parse((string) $row->created_at, 'UTC'),
            ])
            ->values()
            ->all();
    }
}
