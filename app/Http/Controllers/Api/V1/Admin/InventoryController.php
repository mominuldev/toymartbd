<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedgerReport;
use App\Domain\Inventory\Services\StockOverview;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inventory\InventoryFilters;
use App\Http\Resources\Admin\InventoryMovementResource;
use App\Http\Resources\Admin\InventoryReconciliationResource;
use App\Http\Resources\Admin\InventoryStockRowResource;
use App\Http\Resources\Admin\InventorySummaryResource;
use App\Http\Resources\Admin\InventoryVariantPart;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin inventory read side (06-admin-feature-checklist §2.7): the stock overview per
 * location, the append-only movements ledger and its reconciliation. All need
 * inventory.view; cost and valuation figures additionally need catalog.view_cost.
 * Adjustments are written by InventoryAdjustmentController.
 */
final class InventoryController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /inventory/summary` — stat cards, location cards and tab counts for one location.
     */
    #[QueryParameter('filter[location_id]', 'Stock location; the default location when omitted.', type: 'int')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid value (`bad-request`)')]
    public function summary(Request $request, StockOverview $overview): InventorySummaryResource
    {
        $this->authorize('viewAny', StockLevel::class);
        $filters = InventoryFilters::from($request, ['location_id']);

        return new InventorySummaryResource($overview->summary((int) $filters->locationId()));
    }

    /**
     * `GET /inventory/stock` — one row per variant at a location, cursor-paginated,
     * ordered by product then variant.
     */
    #[QueryParameter('filter[location_id]', 'Stock location; the default location when omitted.', type: 'int')]
    #[QueryParameter('filter[state]', '`low` (0 < available ≤ reorder point), `out` (available ≤ 0) or `idle` (stock, but no movement for 90 days).', type: "'low'|'out'|'idle'")]
    #[QueryParameter('filter[search]', 'Contains-match on product name, name_bn, SKU or barcode.', type: 'string')]
    #[QueryParameter('filter[product_id]', 'Only this product\'s variants.', type: 'int')]
    #[QueryParameter('filter[variant_id]', 'Only this variant.', type: 'int')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, a `sort`, or an invalid filter value (`bad-request`)')]
    public function stock(Request $request, StockOverview $overview): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockLevel::class);
        $filters = InventoryFilters::from($request, ['location_id', 'state', 'search', 'product_id', 'variant_id']);
        $locationId = (int) $filters->locationId();

        $query = $overview->rows($locationId);
        if (($state = $filters->state()) !== null) {
            $overview->whereState($query, $state, $locationId);
        }
        if (($search = $filters->search()) !== null) {
            $overview->whereSearch($query, $search);
        }
        if (($productId = $filters->id('product_id')) !== null) {
            $query->where('product_variants.product_id', $productId);
        }
        if (($variantId = $filters->id('variant_id')) !== null) {
            $query->where('product_variants.id', $variantId);
        }

        $page = $query
            ->orderBy('product_variants.product_id')
            ->orderBy('product_variants.id')
            ->with(InventoryVariantPart::RELATIONS)
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        $variantIds = $page->getCollection()->map(static fn (ProductVariant $v): int => $v->id)->values()->all();
        $elsewhere = $overview->elsewhere($variantIds, $locationId);
        $page->getCollection()->each(static fn (ProductVariant $v) => $v->setAttribute('elsewhere', $elsewhere[$v->id] ?? []));

        return InventoryStockRowResource::collection($page);
    }

    /**
     * `GET /inventory/movements` — the append-only stock ledger, newest first, cursor-paginated.
     */
    #[QueryParameter('filter[variant_id]', 'Only this variant.', type: 'int')]
    #[QueryParameter('filter[product_id]', 'Only this product\'s variants.', type: 'int')]
    #[QueryParameter('filter[location_id]', 'Only this location (every location when omitted).', type: 'int')]
    #[QueryParameter('filter[type]', 'Comma-separated movement types, e.g. `damage_out,adjustment_out`.', type: 'string')]
    #[QueryParameter('filter[from]', 'First Dhaka calendar day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka calendar day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, a `sort`, or an invalid filter value (`bad-request`)')]
    public function movements(Request $request, StockLedgerReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockMovement::class);
        $filters = InventoryFilters::from($request, ['variant_id', 'product_id', 'location_id', 'type', 'from', 'to']);

        $query = $report->movements();
        if (($variantId = $filters->id('variant_id')) !== null) {
            $query->where('stock_movements.variant_id', $variantId);
        }
        if (($productId = $filters->id('product_id')) !== null) {
            $query->whereIn('stock_movements.variant_id', ProductVariant::query()->where('product_id', $productId)->select('id'));
        }
        if (($locationId = $filters->locationId(fallback: false)) !== null) {
            $query->where('stock_movements.location_id', $locationId);
        }
        if (($types = $filters->types()) !== []) {
            $query->whereIn('stock_movements.type', array_map(static fn (StockMovementType $t): string => $t->value, $types));
        }
        if (($from = $filters->fromInstant()) !== null) {
            $query->where('stock_movements.created_at', '>=', $from);
        }
        if (($to = $filters->toInstant()) !== null) {
            $query->where('stock_movements.created_at', '<', $to);
        }

        $page = $query
            ->orderByDesc('stock_movements.id')
            ->with(['variant.product.images'])
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return InventoryMovementResource::collection($page);
    }

    /**
     * `GET /inventory/movements/reconciliation` — opening / in / out / closing units for the
     * period and the ledger-vs-levels invariant check, for the same scope as the ledger
     * (the type filter does not apply: a balance needs every movement).
     */
    #[QueryParameter('filter[variant_id]', 'Only this variant.', type: 'int')]
    #[QueryParameter('filter[product_id]', 'Only this product\'s variants.', type: 'int')]
    #[QueryParameter('filter[location_id]', 'Only this location (every location when omitted).', type: 'int')]
    #[QueryParameter('filter[from]', 'First Dhaka calendar day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka calendar day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid value (`bad-request`)')]
    public function reconciliation(Request $request, StockLedgerReport $report): InventoryReconciliationResource
    {
        $this->authorize('viewAny', StockMovement::class);
        $filters = InventoryFilters::from($request, ['variant_id', 'product_id', 'location_id', 'from', 'to']);

        return new InventoryReconciliationResource($report->reconciliation([
            'variant_id' => $filters->id('variant_id'),
            'product_id' => $filters->id('product_id'),
            'location_id' => $filters->locationId(fallback: false),
            'from' => $filters->fromInstant(),
            'to' => $filters->toInstant(),
        ]));
    }
}
