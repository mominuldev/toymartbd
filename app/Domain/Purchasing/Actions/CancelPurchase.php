<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\PurchaseStatusChanged;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Purchase;
use Illuminate\Support\Facades\DB;

/**
 * draft/ordered → cancelled. Refused (409) once any unit has been received: those
 * units are on the shelf and the supplier is owed for them, so the PO stays partially
 * received. Never deleted.
 */
final readonly class CancelPurchase
{
    public function handle(Purchase $purchase): Purchase
    {
        return DB::transaction(static function () use ($purchase): Purchase {
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ((int) $locked->items()->sum('quantity_received') > 0) {
                throw PurchaseConflict::alreadyReceived((string) $locked->number);
            }
            if (! $locked->status->canTransitionTo(PurchaseStatus::Cancelled)) {
                throw PurchaseConflict::transition((string) $locked->number, $locked->status->value, PurchaseStatus::Cancelled->value);
            }

            $from = $locked->status;
            $locked->status = PurchaseStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();
            PurchaseStatusChanged::dispatch($locked->id, $from->value, PurchaseStatus::Cancelled->value);

            return $locked->load('items');
        });
    }
}
