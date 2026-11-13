<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Actions;

use App\Domain\Tracking\Models\OrderTrackingContext;

/**
 * Retention for `order_tracking_contexts` (docs/api/tracking.md §3.3): blanks the client IP,
 * user agent, GA ids, Meta click ids and page URL of every row older than
 * `tracking.context_retention_days` (default 90) and stamps `redacted_at`. The consent
 * record stays — it is evidence of what the shopper agreed to, not personal data about them.
 * A bulk UPDATE in chunks; no transaction needed. Returns the number of rows redacted.
 */
final readonly class PruneOrderTrackingContexts
{
    public function handle(?int $days = null): int
    {
        $days ??= (int) config('tracking.context_retention_days', 90);
        $blank = array_fill_keys(OrderTrackingContext::PERSONAL_COLUMNS, null);
        $total = 0;

        OrderTrackingContext::query()
            ->whereNull('redacted_at')
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(500, function ($rows) use ($blank, &$total): void {
                $total += OrderTrackingContext::query()
                    ->whereKey($rows->modelKeys())
                    ->update($blank + ['redacted_at' => now()]);
            });

        return $total;
    }
}
