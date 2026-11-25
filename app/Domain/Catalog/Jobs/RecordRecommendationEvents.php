<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-inserts a batch of `recommendation_events` (rule 25d) on the `index` queue, so recording
 * never slows a page or a checkout. Insert-only, one statement: the table is append-only.
 *
 * Rows naming a recommended product that does not exist are dropped (one lookup per batch) — a
 * stale or forged id is noise in the funnel, not a measurement. A retry after a partial failure
 * cannot double-count: the single INSERT is atomic.
 */
final class RecordRecommendationEvents implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string QUEUE = 'index';

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  list<array{placement: string, event: string, source_product_id: int|null, recommended_product_id: int, variant_id: int|null, session_id: string|null, customer_id: int|null, order_id: int|null, occurred_at: string}>  $rows
     */
    public function __construct(public readonly array $rows)
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        if ($this->rows === []) {
            return;
        }

        $ids = array_values(array_unique(array_map(static fn (array $r): int => $r['recommended_product_id'], $this->rows)));
        /** @var array<int, true> $known */
        $known = array_fill_keys(DB::table('products')->whereIn('id', $ids)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), true);

        $rows = array_values(array_filter($this->rows, static fn (array $r): bool => isset($known[$r['recommended_product_id']])));
        if ($rows !== []) {
            DB::table('recommendation_events')->insert($rows);
        }
    }
}
