<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A receipt's landed costs (freight, customs/duty, other) were charged by someone other
 * than the supplier — a freight forwarder or C&F agent — so the supplier payable does not
 * include them. Held until the receipt commits. Finance books one capitalised expense from
 * it: cash out when paid, never a recognised P&L cost (the costs are already in the stock's
 * landed cost and reach the P&L as COGS on sale). Carries everything the listener needs so
 * Finance never reads Purchasing's tables. Amounts are 2-dp decimal strings.
 */
final readonly class LandedCostsPaidToThirdParty implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $receiptId,
        public string $receiptNumber,
        public string $purchaseNumber,
        public string $freight,
        public string $customsDuty,
        public string $otherCost,
        public string $total,
        /** Dhaka calendar date of the receipt, Y-m-d. */
        public string $receivedOn,
        public ?string $vendor,
        public ?string $reference,
        /** An expense payment method value, or null when not paid yet. */
        public ?string $paymentMethod,
        public ?int $userId,
    ) {}
}
