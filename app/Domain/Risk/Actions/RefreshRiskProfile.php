<?php

declare(strict_types=1);

namespace App\Domain\Risk\Actions;

use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Services\PhoneHistoryReader;
use App\Domain\Risk\Services\RiskScorer;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds one phone's profile — counts, phone-level score and breakdown, `score_computed_at`
 * — from its orders (§3.3). A projection, so a replayed or out-of-order event cannot double
 * count: every run recomputes from the source. Staff-owned columns (status, reason, who/when,
 * note) and the courier columns are never touched here.
 *
 * Concurrency: the row is inserted (if missing) before it is locked, so different phones
 * never contend. Two workers rebuilding the SAME new phone at once can still collide — on
 * MySQL as a 1213 (shared lock on the duplicate, then FOR UPDATE), on any engine possibly
 * as a `phone` unique violation. Either way the loser is retried from the top — bounded, like PlaceOrder's 3× — and the second attempt finds the winner's row,
 * locks it and recomputes it. Recomputing is idempotent, so a retry can never double count.
 */
final readonly class RefreshRiskProfile
{
    public const int ATTEMPTS = 3;

    public function __construct(private PhoneHistoryReader $history, private RiskScorer $scorer) {}

    public function handle(string $e164): CustomerRiskProfile
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // At transaction level 1 Laravel retries a deadlock itself; nested (a caller's
                // transaction) it throws DeadlockException, caught below.
                return DB::transaction(fn (): CustomerRiskProfile => $this->rebuild($e164), self::ATTEMPTS);
            } catch (UniqueConstraintViolationException|DeadlockException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function rebuild(string $e164): CustomerRiskProfile
    {
        // Never lock a phone with no row (P9, LOAD-AND-CONCURRENCY F-6): FOR UPDATE on a
        // missing key is a gap lock, and rebuilds for two DIFFERENT new phones in the same
        // gap then deadlocked on their inserts — every pair of first orders at launch. A
        // plain read, an insert of the missing row (inserts do not block each other; it is
        // only visible on commit), then a record lock on the row that now exists.
        if (! CustomerRiskProfile::query()->where('phone', $e164)->exists()) {
            $now = now();
            DB::table('customer_risk_profiles')->insertOrIgnore(['phone' => $e164, 'created_at' => $now, 'updated_at' => $now]);
        }
        $profile = CustomerRiskProfile::query()->where('phone', $e164)->lockForUpdate()->first()
            ?? new CustomerRiskProfile(['phone' => $e164]);

        $history = $this->history->forPhone($e164);
        $breakdown = $this->scorer->score($history);

        $profile->fill([
            'customer_id' => $history->customerId ?? $profile->customer_id,
            'total_orders' => $history->totalOrders,
            'delivered_count' => $history->deliveredCount,
            'cancelled_count' => $history->cancelledCount,
            'returned_count' => $history->returnedCount,
            'failed_delivery_count' => $history->failedDeliveryCount,
            'refused_count' => $history->refusedCount,
            'internal_score' => $breakdown->score,
            'score_breakdown' => $breakdown->factors,
            'score_computed_at' => now(),
            'last_order_at' => $history->lastOrderAt,
        ]);
        $profile->save();

        return $profile;
    }
}
