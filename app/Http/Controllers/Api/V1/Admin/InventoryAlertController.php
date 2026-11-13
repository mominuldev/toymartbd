<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Actions\AcknowledgeInventoryAlert;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inventory\AcknowledgeInventoryAlertRequest;
use App\Http\Requests\Admin\Inventory\InventoryFilters;
use App\Http\Resources\Admin\InventoryAlertResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Inventory alerts (P5-INV): low/out-of-stock levels and the nightly reconciliation's
 * drift. Reading needs inventory.view; acknowledging needs inventory.approve_adjustments.
 */
final class InventoryAlertController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /inventory/alerts` — newest first, cursor-paginated; open alerts unless
     * `filter[status]` says otherwise. `meta.counts` holds the open count per kind.
     */
    #[QueryParameter('filter[kind]', 'Comma-separated kinds: `low_stock`, `out_of_stock`, `ledger_drift`, `reserved_drift`.', type: 'string')]
    #[QueryParameter('filter[status]', 'Comma-separated statuses (`open` when omitted): `open`, `resolved`, `acknowledged`.', type: 'string')]
    #[QueryParameter('filter[location_id]', 'Only this location.', type: 'int')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, a `sort`, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InventoryAlert::class);
        $filters = InventoryFilters::from($request, ['kind', 'status', 'location_id']);

        $kinds = $filters->listOf('kind', array_map(static fn (InventoryAlertKind $k): string => $k->value, InventoryAlertKind::cases()));
        $statuses = $filters->listOf('status', array_map(static fn (InventoryAlertStatus $s): string => $s->value, InventoryAlertStatus::cases()));

        $query = InventoryAlert::query()->whereIn('status', $statuses === [] ? [InventoryAlertStatus::Open->value] : $statuses);
        if ($kinds !== []) {
            $query->whereIn('kind', $kinds);
        }
        if (($locationId = $filters->locationId(fallback: false)) !== null) {
            $query->where('location_id', $locationId);
        }

        $counts = InventoryAlert::query()->open()->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return InventoryAlertResource::collection(
            $query->orderByDesc('id')->with(InventoryAlertResource::RELATIONS)->cursorPaginate(self::PER_PAGE)->withQueryString(),
        )->additional(['meta' => ['counts' => array_combine(
            array_map(static fn (InventoryAlertKind $k): string => $k->value, InventoryAlertKind::cases()),
            array_map(static fn (InventoryAlertKind $k): int => (int) ($counts[$k->value] ?? 0), InventoryAlertKind::cases()),
        )]]);
    }

    /** `POST /inventory/alerts/{alert}/acknowledge` — closes an open alert; changes no stock. 409 when not open. */
    #[OpenApiResponse(403, 'Missing inventory.approve_adjustments (`forbidden`)')]
    #[OpenApiResponse(409, 'Not open (`conflict`)')]
    public function acknowledge(AcknowledgeInventoryAlertRequest $request, InventoryAlert $alert, AcknowledgeInventoryAlert $acknowledge): InventoryAlertResource
    {
        return new InventoryAlertResource($acknowledge->handle($alert->id, (int) $request->user()?->id, $request->note()));
    }
}
