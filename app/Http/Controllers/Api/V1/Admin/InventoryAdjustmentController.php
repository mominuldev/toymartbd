<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\ApproveStockAdjustment;
use App\Domain\Inventory\Actions\RejectStockAdjustment;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inventory\ApproveStockAdjustmentRequest;
use App\Http\Requests\Admin\Inventory\InventoryFilters;
use App\Http\Requests\Admin\Inventory\RejectStockAdjustmentRequest;
use App\Http\Requests\Admin\Inventory\StoreStockAdjustmentRequest;
use App\Http\Resources\Admin\InventoryAdjustmentResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stock adjustments: the only way staff change on-hand stock by hand. Each one is a
 * document with a reason; above the approval threshold (config/inventory.php) it waits
 * `pending` for someone with inventory.approve_adjustments, and only an applied or
 * approved adjustment writes ledger movements.
 */
final class InventoryAdjustmentController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /inventory/adjustments` — newest first, cursor-paginated (the approval queue is
     * `filter[status]=pending`). `meta.counts.pending` is the queue size, unaffected by filters.
     */
    #[QueryParameter('filter[status]', 'Comma-separated statuses: `applied`, `pending`, `approved`, `rejected`.', type: 'string')]
    #[QueryParameter('filter[reason]', 'Comma-separated reasons, e.g. `damage,theft`.', type: 'string')]
    #[QueryParameter('filter[location_id]', 'Only this location.', type: 'int')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, a `sort`, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockAdjustment::class);
        $filters = InventoryFilters::from($request, ['status', 'reason', 'location_id']);

        $query = StockAdjustment::query();
        if (($statuses = $filters->listOf('status', array_map(static fn (StockAdjustmentStatus $s): string => $s->value, StockAdjustmentStatus::cases()))) !== []) {
            $query->whereIn('status', $statuses);
        }
        if (($reasons = $filters->listOf('reason', array_map(static fn (StockAdjustmentReason $r): string => $r->value, StockAdjustmentReason::cases()))) !== []) {
            $query->whereIn('reason', $reasons);
        }
        if (($locationId = $filters->locationId(fallback: false)) !== null) {
            $query->where('location_id', $locationId);
        }

        $page = $query->orderByDesc('id')->with(InventoryAdjustmentResource::RELATIONS)->cursorPaginate(self::PER_PAGE)->withQueryString();

        return InventoryAdjustmentResource::collection($page)->additional(['meta' => ['counts' => [
            'pending' => StockAdjustment::query()->where('status', StockAdjustmentStatus::Pending->value)->count(),
        ]]]);
    }

    /** `GET /inventory/adjustments/{adjustment}` — needs inventory.view. */
    public function show(StockAdjustment $adjustment): InventoryAdjustmentResource
    {
        $this->authorize('view', $adjustment);

        return new InventoryAdjustmentResource($adjustment);
    }

    /**
     * `POST /inventory/adjustments` — needs inventory.adjust and an `Idempotency-Key`.
     * 422 names each refused line: no change, a direction the reason does not allow
     * (found only adds; damage, loss, theft, expiry and gift/sample only remove), or on
     * hand dropping below what is reserved for open orders. Above the approval threshold
     * it is created `pending` and moves nothing — unless the requester holds
     * inventory.approve_adjustments, in which case it is `approved` by them at once.
     */
    #[OpenApiResponse(403, 'Missing inventory.adjust (`forbidden`)')]
    public function store(StoreStockAdjustmentRequest $request, AdjustStock $adjustStock): JsonResponse
    {
        $user = $request->user();
        $adjustment = $adjustStock->handle($request->toData(), $user?->id, $user?->can('approve', StockAdjustment::class) ?? false);

        return (new InventoryAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }

    /**
     * `POST /inventory/adjustments/{adjustment}/approve` — needs inventory.approve_adjustments
     * and an `Idempotency-Key`. Re-checks every line against the stock now and writes the
     * movements. 409 when it is not pending; 422 when a line can no longer apply or the
     * approver is the requester.
     */
    #[OpenApiResponse(403, 'Missing inventory.approve_adjustments (`forbidden`)')]
    #[OpenApiResponse(409, 'Not pending (`conflict`)')]
    public function approve(ApproveStockAdjustmentRequest $request, StockAdjustment $adjustment, ApproveStockAdjustment $approve): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource($approve->handle($adjustment->id, (int) $request->user()?->id, $request->note()));
    }

    /**
     * `POST /inventory/adjustments/{adjustment}/reject` — needs inventory.approve_adjustments.
     * Writes no movement. 409 when it is not pending.
     */
    #[OpenApiResponse(403, 'Missing inventory.approve_adjustments (`forbidden`)')]
    #[OpenApiResponse(409, 'Not pending (`conflict`)')]
    public function reject(RejectStockAdjustmentRequest $request, StockAdjustment $adjustment, RejectStockAdjustment $reject): InventoryAdjustmentResource
    {
        return new InventoryAdjustmentResource($reject->handle($adjustment->id, (int) $request->user()?->id, (string) $request->note()));
    }
}
