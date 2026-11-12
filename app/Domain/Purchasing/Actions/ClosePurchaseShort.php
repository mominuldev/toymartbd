<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\PurchaseClosedShort;
use App\Domain\Purchasing\Events\PurchaseStatusChanged;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;

/**
 * partially_received → closed_short (owner decision 2026-11-13): the supplier will not
 * send the rest. Terminal — no further receipt is accepted. Records who closed it, when
 * and why on the PO itself.
 *
 * Money: nothing changes. The supplier is owed only for what arrived (received_value is
 * built from receipts), so the payable stays received value − payments; the unreceived
 * lines were never payable. Stock and costs are untouched.
 */
final readonly class ClosePurchaseShort
{
    public function handle(Purchase $purchase, string $reason, ?int $userId = null): Purchase
    {
        return DB::transaction(static function () use ($purchase, $reason, $userId): Purchase {
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if (! $locked->status->canTransitionTo(PurchaseStatus::ClosedShort)) {
                throw PurchaseConflict::transition((string) $locked->number, $locked->status->value, PurchaseStatus::ClosedShort->value);
            }

            $short = (int) $locked->items()->get()->sum(static fn (PurchaseItem $i): int => $i->quantityOutstanding());
            $from = $locked->status;
            $locked->forceFill([
                'status' => PurchaseStatus::ClosedShort,
                'closed_at' => now(),
                'closed_by' => $userId,
                'close_reason' => $reason,
            ])->save();

            PurchaseStatusChanged::dispatch($locked->id, $from->value, PurchaseStatus::ClosedShort->value);
            PurchaseClosedShort::dispatch($locked->id, $short, $reason, $userId);

            return $locked->load('items');
        });
    }
}
