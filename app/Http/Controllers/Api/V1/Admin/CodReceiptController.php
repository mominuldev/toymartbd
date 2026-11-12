<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\RecordCodReceived;
use App\Domain\Finance\Services\Receivables;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\RecordCodReceiptRequest;
use App\Http\Resources\Admin\ManualCodReceiptResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * "Record COD received" (P5): cash from the courier for one delivered COD order, written as
 * `cod_received` — cash, not revenue (rule 10). Stands in for Phase 6 settlement matching.
 */
final class CodReceiptController extends ApiController
{
    /** `POST /finance/orders/{order}/cod-receipts` — needs finance.reconcile_cod and an `Idempotency-Key`. */
    #[OpenApiResponse(409, 'Not a COD order, not delivered yet, or more than is outstanding (`conflict`)')]
    public function store(RecordCodReceiptRequest $request, Order $order, RecordCodReceived $record, Receivables $receivables): JsonResponse
    {
        $receipt = $record->handle($order, $request->amount(), $request->receivedOn(), $request->reference(), $request->note(), $request->user()?->id);
        $receipt->setAttribute('order_outstanding', $receivables->forOrder($order->id)->toDecimal());

        return (new ManualCodReceiptResource($receipt))->response()->setStatusCode(201);
    }
}
