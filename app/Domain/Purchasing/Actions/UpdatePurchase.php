<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\DTOs\PurchaseData;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a draft PO's header and lines. Only a draft is editable — once ordered, the
 * supplier has the PO, and what arrives is recorded by receipts (409 otherwise). A
 * draft has no receipts, so its lines are replaced wholesale.
 */
final readonly class UpdatePurchase
{
    public function handle(Purchase $purchase, PurchaseData $data): Purchase
    {
        return DB::transaction(static function () use ($purchase, $data): Purchase {
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if (! $locked->status->isEditable()) {
                throw PurchaseConflict::notEditable((string) $locked->number, $locked->status->value);
            }

            CreatePurchase::fillHeader($locked, $data);
            PurchaseItem::query()->where('purchase_id', $locked->id)->delete();
            CreatePurchase::writeLines($locked, $data->lines);

            return $locked->load('items');
        });
    }
}
