<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Services\ReceiptCalculator;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Purchasing\ReceivePurchaseRequest;
use App\Http\Resources\Admin\PurchaseResource;
use App\Http\Resources\Admin\ReceiptQuoteResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Receiving stock against a PO (DATABASE-DESIGN §4.6): a quote that writes nothing, and
 * the receipt itself. Both run ReceiptCalculator, so the dialog shows what is written.
 */
final class PurchaseReceiptController extends ApiController
{
    /**
     * `POST /purchases/{purchase}/receipts/quote` — per-line value, landed-cost share, landed
     * unit cost and the weighted-average step, computed by the server (rule 1). Writes
     * nothing. Needs purchasing.receive. 422 on an over-receipt or unknown line, 409 unless the PO is receivable.
     */
    #[OpenApiResponse(409, 'Not ordered / partially received (`conflict`)')]
    public function quote(ReceivePurchaseRequest $request, Purchase $purchase, ReceiptCalculator $calculator): ReceiptQuoteResource
    {
        if (! $purchase->status->canReceive()) {
            throw PurchaseConflict::notReceivable((string) $purchase->number, $purchase->status->value);
        }

        return new ReceiptQuoteResource($calculator->quote($purchase->load('items'), $request->toData()));
    }

    /**
     * `POST /purchases/{purchase}/receipts` — receives the units into stock at the landed
     * cost; needs purchasing.receive and an `Idempotency-Key`. Returns the updated PO
     * (its newest receipt last). 409 unless the PO is ordered or partially received.
     */
    #[OpenApiResponse(409, 'Not ordered / partially received (`conflict`)')]
    public function store(ReceivePurchaseRequest $request, Purchase $purchase, ReceivePurchase $receive): JsonResponse
    {
        $receive->handle($purchase, $request->toData(), $request->user()?->id);

        return (new PurchaseResource(PurchaseController::loaded($purchase->refresh())))->response()->setStatusCode(201);
    }
}
