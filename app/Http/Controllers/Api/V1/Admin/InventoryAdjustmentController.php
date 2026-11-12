<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Actions\AdjustStock;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inventory\StoreStockAdjustmentRequest;
use App\Http\Resources\Admin\InventoryAdjustmentResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Stock adjustments: the only way staff change on-hand stock by hand. Each one is a
 * document with a reason, and writes one ledger movement per line.
 */
final class InventoryAdjustmentController extends ApiController
{
    /**
     * `POST /inventory/adjustments` — needs inventory.adjust and an `Idempotency-Key`.
     * 422 names each refused line: no change, a direction the reason does not allow
     * (found only adds; damage, loss, expiry and gift/sample only remove), or on hand
     * dropping below what is reserved for open orders.
     */
    #[OpenApiResponse(403, 'Missing inventory.adjust (`forbidden`)')]
    public function store(StoreStockAdjustmentRequest $request, AdjustStock $adjustStock): JsonResponse
    {
        $adjustment = $adjustStock->handle($request->toData(), $request->user()?->id);

        return (new InventoryAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }
}
