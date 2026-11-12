<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\DTOs\PurchaseData;
use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Services\PurchaseTotals;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a draft PO (DATABASE-DESIGN §3.2). Line totals and the PO total are computed
 * here; a line whose discount exceeds its value is refused. Drafts are edited by
 * UpdatePurchase and sent to the supplier by PlacePurchaseOrder.
 */
final readonly class CreatePurchase
{
    public function handle(PurchaseData $data, ?int $userId = null): Purchase
    {
        return DB::transaction(static function () use ($data, $userId): Purchase {
            $purchase = new Purchase([
                'supplier_id' => $data->supplierId,
                'location_id' => $data->locationId ?? StockLocation::defaultId(),
                'status' => PurchaseStatus::Draft,
                'created_by' => $userId,
            ]);
            self::fillHeader($purchase, $data);
            $purchase->save();
            $purchase->number = Purchase::numberFor($purchase->id, $purchase->created_at ?? now());
            self::writeLines($purchase, $data->lines);

            return $purchase->load('items');
        });
    }

    /** @internal shared with UpdatePurchase */
    public static function fillHeader(Purchase $purchase, PurchaseData $data): void
    {
        $purchase->supplier_id = $data->supplierId;
        $purchase->location_id = $data->locationId ?? $purchase->location_id;
        $purchase->order_date = Carbon::instance($data->orderDate);
        $purchase->expected_date = $data->expectedDate === null ? null : Carbon::instance($data->expectedDate);
        $purchase->supplier_invoice_no = $data->supplierInvoiceNo;
        $purchase->note = $data->note;
    }

    /**
     * Writes the lines and the header totals, then saves the PO.
     *
     * @internal shared with UpdatePurchase
     *
     * @param  list<PurchaseLineData>  $lines
     */
    public static function writeLines(Purchase $purchase, array $lines): void
    {
        $subtotal = Money::zero();
        $discount = Money::zero();
        $errors = [];
        foreach ($lines as $index => $line) {
            $gross = PurchaseTotals::lineGross($line);
            if ($line->discount->greaterThan($gross)) {
                $errors["lines.{$index}.discount"] = __('purchasing.lines.discount_exceeds');
            }
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus($line->discount);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($lines as $position => $line) {
            PurchaseItem::query()->create([
                'purchase_id' => $purchase->id,
                'variant_id' => $line->variantId,
                'quantity_ordered' => $line->quantity,
                'unit_cost' => $line->unitCost,
                'discount' => $line->discount,
                'line_total' => PurchaseTotals::lineTotal($line),
                'position' => $position,
            ]);
        }

        $purchase->subtotal = $subtotal;
        $purchase->discount = $discount;
        PurchaseTotals::refresh($purchase);
        $purchase->save();
    }
}
