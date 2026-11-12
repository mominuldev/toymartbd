<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Inventory\Models\StockTransfer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inventory\InventoryFilters;
use App\Http\Requests\Admin\Inventory\StoreStockTransferRequest;
use App\Http\Resources\Admin\InventoryTransferResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stock transfers between locations (P5-INV): `transfer_out` at the source and
 * `transfer_in` at the destination in one transaction.
 */
final class InventoryTransferController extends ApiController
{
    public const int PER_PAGE = 25;

    /** `GET /inventory/transfers` — newest first, cursor-paginated; needs inventory.view. */
    #[QueryParameter('filter[location_id]', 'Transfers out of or into this location.', type: 'int')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, a `sort`, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockTransfer::class);
        $filters = InventoryFilters::from($request, ['location_id']);

        $query = StockTransfer::query();
        if (($locationId = $filters->locationId(fallback: false)) !== null) {
            $query->where(static fn (Builder $q) => $q->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId));
        }

        return InventoryTransferResource::collection(
            $query->orderByDesc('id')->with(InventoryTransferResource::RELATIONS)->cursorPaginate(self::PER_PAGE)->withQueryString(),
        );
    }

    /**
     * `POST /inventory/transfers` — needs inventory.transfer and an `Idempotency-Key`. 422
     * names each line asking for more than is available at the source (on hand minus
     * reserved).
     */
    #[OpenApiResponse(403, 'Missing inventory.transfer (`forbidden`)')]
    public function store(StoreStockTransferRequest $request, TransferStock $transfer): JsonResponse
    {
        $created = $transfer->handle($request->toData(), $request->user()?->id);

        return (new InventoryTransferResource($created))->response()->setStatusCode(201);
    }
}
