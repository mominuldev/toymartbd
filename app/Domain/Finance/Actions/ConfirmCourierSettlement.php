<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\SettlementStatus;
use App\Domain\Finance\Events\CourierSettlementReconciled;
use App\Domain\Finance\Exceptions\SettlementRefused;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\CourierSettlementItem;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a reviewed settlement (§4.5): refused while any variance line has no decision.
 * Marks every bookable line (clean match, or an accepted short) `booked_at`, sets the
 * settlement `reconciled`, and — after commit — CourierSettlementReconciled lets Finance's
 * Settlement listener write the ledger. A second confirm is a 409; a replayed event writes
 * nothing (the ledger is idempotent per line).
 */
final readonly class ConfirmCourierSettlement
{
    /** @throws SettlementRefused */
    public function handle(CourierSettlement $settlement, ?int $userId): CourierSettlement
    {
        return DB::transaction(function () use ($settlement, $userId): CourierSettlement {
            $locked = CourierSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status === SettlementStatus::Reconciled) {
                throw SettlementRefused::alreadyReconciled();
            }
            $unresolved = $locked->unresolvedCount();
            if ($unresolved > 0) {
                throw SettlementRefused::unresolved($unresolved);
            }

            $now = DhakaDate::instant();
            $locked->items()->orderBy('id')->get()
                ->filter(static fn (CourierSettlementItem $i): bool => $i->isBookable())
                ->each(static fn (CourierSettlementItem $i) => $i->forceFill(['booked_at' => $now])->save());

            $locked->forceFill([
                'status' => SettlementStatus::Reconciled,
                'confirmed_by' => $userId,
                'confirmed_at' => $now,
            ])->save();

            event(new CourierSettlementReconciled($locked->id));

            return $locked;
        });
    }
}
