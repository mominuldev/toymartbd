<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\PurchaseStatusChanged;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Purchase;
use Illuminate\Support\Facades\DB;

/** draft → ordered: the PO has been sent to the supplier; stock can now be received against it. */
final readonly class PlacePurchaseOrder
{
    public function handle(Purchase $purchase): Purchase
    {
        return DB::transaction(static function () use ($purchase): Purchase {
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if (! $locked->status->canTransitionTo(PurchaseStatus::Ordered)) {
                throw PurchaseConflict::transition((string) $locked->number, $locked->status->value, PurchaseStatus::Ordered->value);
            }

            $from = $locked->status;
            $locked->status = PurchaseStatus::Ordered;
            $locked->ordered_at = now();
            $locked->save();
            PurchaseStatusChanged::dispatch($locked->id, $from->value, PurchaseStatus::Ordered->value);

            return $locked->load('items');
        });
    }
}
