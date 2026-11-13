<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Enums\SettlementStatus;
use App\Domain\Finance\Exceptions\SettlementRefused;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\CourierSettlementItem;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

/**
 * A person decides on one settlement line: `accepted` (book what the courier actually paid —
 * only for a `short` line) or `excluded` (not booked; the receivable stays open and the
 * difference is taken up with the courier). Nothing is adjusted automatically; a decision
 * can be changed until the settlement is confirmed.
 */
final readonly class ReviewSettlementLine
{
    /** @throws SettlementRefused */
    public function handle(CourierSettlement $settlement, CourierSettlementItem $item, SettlementReview $decision, ?string $note, ?int $userId): CourierSettlementItem
    {
        return DB::transaction(function () use ($settlement, $item, $decision, $note, $userId): CourierSettlementItem {
            $locked = CourierSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status === SettlementStatus::Reconciled) {
                throw SettlementRefused::alreadyReconciled();
            }
            if ($decision === SettlementReview::Accepted && ! $item->variance_type->acceptable()) {
                throw SettlementRefused::notAcceptable($item->variance_type->value);
            }

            $item->forceFill([
                'review_status' => $decision,
                'review_note' => $note,
                'reviewed_by' => $userId,
                'reviewed_at' => DhakaDate::instant(),
            ])->save();

            return $item;
        });
    }
}
