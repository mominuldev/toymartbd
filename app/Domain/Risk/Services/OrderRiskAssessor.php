<?php

declare(strict_types=1);

namespace App\Domain\Risk\Services;

use App\Domain\Risk\DTOs\OrderContext;
use App\Domain\Risk\DTOs\PhoneHistory;
use App\Domain\Risk\DTOs\RiskAssessment;
use App\Domain\Risk\DTOs\RiskInput;
use App\Domain\Risk\Enums\RiskProfileStatus;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Support\Money;

/**
 * The checkout risk check (DATABASE-DESIGN §5.2, rules 23, 24b, 33b) — the ONE place an
 * order's hold decision is made (PlaceOrder only asks). Our own order history only: courier
 * data is shown to staff beside it, never mixed in (rule 24).
 *
 * Hold reasons (any one → `on_hold` + verification pending; NEVER a cancel):
 *  - `high_risk_score`             score ≥ the high threshold (not for a whitelisted phone);
 *  - `first_order_above_threshold` an unknown phone at or above the first-order threshold —
 *                                  regardless of score or status (rule 33b);
 *  - `cod_advance_required`        the COD advance must be arranged by phone;
 *  - `blacklisted` / `watchlist`   staff put the phone on a list.
 */
final readonly class OrderRiskAssessor
{
    public function __construct(
        private RiskSettings $settings,
        private PhoneHistoryReader $history,
        private RiskScorer $scorer,
    ) {}

    public function assess(RiskInput $input): RiskAssessment
    {
        $history = $this->history->forPhone($input->phone->e164());
        if ($input->priorOrders !== null) {
            $history = self::withTotal($history, $input->priorOrders);
        }

        $breakdown = $this->scorer->score($history, new OrderContext($input->orderValue, $input->addressLine, $input->hasLocality));
        $status = CustomerRiskProfile::forPhone($input->phone->e164())?->status;

        $hold = [];
        if ($status?->holdReason() !== null) {
            $hold[] = $status->holdReason();
        }
        if ($breakdown->level === 'high' && $status !== RiskProfileStatus::Whitelist) {
            $hold[] = 'high_risk_score';
        }
        if ($history->totalOrders === 0 && ! $input->orderValue->lessThan($this->settings->firstOrderHoldThreshold())) {
            $hold[] = 'first_order_above_threshold';
        }
        if ($input->codAdvanceRequired) {
            $hold[] = 'cod_advance_required';
        }

        return new RiskAssessment($breakdown->score, $breakdown->level, $breakdown->signals(), $hold, $status?->value);
    }

    public function threshold(): Money
    {
        return $this->settings->firstOrderHoldThreshold();
    }

    private static function withTotal(PhoneHistory $h, int $total): PhoneHistory
    {
        return new PhoneHistory(
            $h->phone, $h->customerId, $total, $h->deliveredCount, $h->cancelledCount, $h->customerCancelCount,
            $h->returnedCount, $h->refusedCount, $h->failedDeliveryCount, $h->averageDeliveredValue, $h->recentOrders, $h->lastOrderAt,
        );
    }
}
