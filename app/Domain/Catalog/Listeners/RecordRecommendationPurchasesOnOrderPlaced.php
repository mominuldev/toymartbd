<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Listeners;

use App\Domain\Catalog\Enums\RecommendationEventType;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Ordering\Events\OrderPlaced;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * OrderPlaced (after commit) → one `purchased` recommendation event per order line that carries a
 * `recommendation_source` (rule 25d) — the bottom of the funnel the storefront cannot report.
 * Queued on `index`, never inside the order's transaction.
 *
 * Catalog may not depend on Ordering's models (deptrac), so the order is read by table name.
 * Idempotent: an order that already has `purchased` events is skipped, so a retried job (or a
 * replayed event) never double-counts. `source_product_id` is unknown at this point — the cart
 * line keeps only the placement — so it stays null; reports attribute by placement.
 */
final class RecordRecommendationPurchasesOnOrderPlaced implements ShouldQueue
{
    public string $queue = 'index';

    public int $tries = 3;

    public function handle(OrderPlaced $event): void
    {
        $order = DB::table('orders')->where('id', $event->orderId)->first(['id', 'customer_id', 'placed_at', 'created_at']);
        if ($order === null) {
            return;
        }

        $purchased = RecommendationEventType::Purchased->value;
        if (DB::table('recommendation_events')->where('order_id', $event->orderId)->where('event', $purchased)->exists()) {
            return;
        }

        $placements = RecommendationPlacement::values();
        $placedAt = $order->placed_at ?? $order->created_at;
        $occurredAt = ($placedAt !== null ? CarbonImmutable::parse((string) $placedAt) : CarbonImmutable::now())->utc()->toDateTimeString();
        $customerId = $order->customer_id !== null ? (int) $order->customer_id : null;

        $rows = DB::table('order_items')
            ->where('order_id', $event->orderId)
            ->whereNotNull('recommendation_source')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->get(['product_id', 'variant_id', 'recommendation_source'])
            ->filter(static fn (object $line): bool => in_array($line->recommendation_source, $placements, true))
            ->map(static fn (object $line): array => [
                'placement' => (string) $line->recommendation_source,
                'event' => $purchased,
                'source_product_id' => null,
                'recommended_product_id' => (int) $line->product_id,
                'variant_id' => $line->variant_id !== null ? (int) $line->variant_id : null,
                'session_id' => null,
                'customer_id' => $customerId,
                'order_id' => (int) $order->id,
                'occurred_at' => $occurredAt,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('recommendation_events')->insert($rows);
        }
    }
}
