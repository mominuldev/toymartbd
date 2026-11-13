<?php

declare(strict_types=1);

namespace App\Domain\Risk\Actions;

use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Services\PhoneHistoryReader;
use App\Domain\Risk\Services\RiskScorer;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds one phone's profile — counts, phone-level score and breakdown, `score_computed_at`
 * — from its orders (§3.3). A projection, so a replayed or out-of-order event cannot double
 * count: every run recomputes from the source. Staff-owned columns (status, reason, who/when,
 * note) and the courier columns are never touched here.
 */
final readonly class RefreshRiskProfile
{
    public function __construct(private PhoneHistoryReader $history, private RiskScorer $scorer) {}

    public function handle(string $e164): CustomerRiskProfile
    {
        return DB::transaction(function () use ($e164): CustomerRiskProfile {
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
        });
    }
}
