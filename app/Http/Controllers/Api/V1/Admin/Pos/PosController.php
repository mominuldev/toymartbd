<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Pos;

use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Pos\Actions\ChargeSale;
use App\Domain\Pos\Actions\CloseRegisterSession;
use App\Domain\Pos\Actions\DiscardHeldSale;
use App\Domain\Pos\Actions\HoldSale;
use App\Domain\Pos\Actions\OpenRegisterSession;
use App\Domain\Pos\Actions\ResumeHeldSale;
use App\Domain\Pos\Enums\CounterTender;
use App\Domain\Pos\Enums\PosSessionStatus;
use App\Domain\Pos\Models\PosHeldSale;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Queries\DrawerTotals;
use App\Domain\Pos\Queries\PosCatalog;
use App\Domain\Pos\Queries\PosCustomerSearch;
use App\Domain\Pos\Services\PosCustomers;
use App\Domain\Pos\Services\PosDues;
use App\Domain\Pos\Services\PosQuotes;
use App\Domain\Pos\Tenders\TenderRegistry;
use App\Http\Controllers\Api\V1\Admin\OrderProblems;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Pos\ChargePosSaleRequest;
use App\Http\Requests\Admin\Pos\ClosePosSessionRequest;
use App\Http\Requests\Admin\Pos\HoldPosSaleRequest;
use App\Http\Requests\Admin\Pos\OpenPosSessionRequest;
use App\Http\Requests\Admin\Pos\PosCatalogRequest;
use App\Http\Requests\Admin\Pos\PosCustomerLookupRequest;
use App\Http\Requests\Admin\Pos\PosCustomerSearchRequest;
use App\Http\Requests\Admin\Pos\QuotePosSaleRequest;
use App\Http\Resources\Admin\Pos\PosCatalogItemResource;
use App\Http\Resources\Admin\Pos\PosContextResource;
use App\Http\Resources\Admin\Pos\PosCustomerMatchResource;
use App\Http\Resources\Admin\Pos\PosCustomerResource;
use App\Http\Resources\Admin\Pos\PosHeldSaleResource;
use App\Http\Resources\Admin\Pos\PosQuoteResource;
use App\Http\Resources\Admin\Pos\PosReceiptResource;
use App\Http\Resources\Admin\Pos\PosSaleResource;
use App\Http\Resources\Admin\Pos\PosSessionResource;
use App\Models\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The POS register (Figma B · 33 241:48158) — docs/api/pos.md. A drawer session is opened at a
 * branch; the grid, the quote, Charge, Hold and the receipt all work that drawer. Every money
 * figure comes from the server (rule 1). Permissions: pos.use (own drawer), pos.manage
 * (close / read anyone's) — PosSessionPolicy.
 */
final class PosController extends ApiController
{
    /** `GET /pos/context` — branches, tenders, category tabs, my open drawer, WhatsApp-receipt availability. */
    public function context(Request $request, TenderRegistry $tenders, PosCatalog $catalog, DrawerTotals $totals, PosDues $dues): PosContextResource
    {
        $this->authorize('viewAny', PosSession::class);
        /** @var User $user */
        $user = $request->user();

        $session = PosSession::query()->with(['location', 'opener'])
            ->where('opened_by', $user->id)->where('status', PosSessionStatus::Open->value)->first();

        return new PosContextResource([
            'locations' => StockLocation::query()->where('is_active', true)
                ->where(static fn ($q) => $q->whereNull('type')->orWhere('type', '!=', StockLocationType::Pickup->value))
                ->orderByRaw("CASE WHEN type = 'retail' THEN 0 ELSE 1 END")->orderByDesc('is_default')->orderBy('id')->get(),
            // Due is listed (last) only for a cashier who may sell on due.
            'tenders' => array_values(array_filter(
                array_map(static fn (string $m) => $tenders->for(CounterTender::from($m)), TenderRegistry::methods()),
                static fn ($t): bool => $t->recordsPayment() || $user->can(Permission::PosSellOnDue->value),
            )),
            'due_limit' => $dues->limit(),
            'categories' => $catalog->tabs(),
            'session' => $session === null ? null : new PosSessionResource(['session' => $session, 'totals' => $totals->for($session)]),
            'can_manage' => $user->can(Permission::PosManage->value),
        ]);
    }

    /** `POST /pos/sessions` — open a drawer. 201. */
    #[OpenApiResponse(409, 'The register already has an open drawer, you already have one open, or the location does not sell (`conflict`)')]
    public function open(OpenPosSessionRequest $request, OpenRegisterSession $open, DrawerTotals $totals): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $open->handle($request->integer('location_id'), $request->string('register')->toString(), $request->openingFloat(), $user->id);

        return (new PosSessionResource(['session' => $session->loadMissing(['location', 'opener']), 'totals' => $totals->for($session)]))->response()->setStatusCode(201);
    }

    /** `GET /pos/sessions/{session}` — the drawer with its running totals. */
    public function show(PosSession $session, DrawerTotals $totals): PosSessionResource
    {
        $this->authorize('view', $session);

        return self::session($session, $totals);
    }

    /** `POST /pos/sessions/{session}/close` — count and close: expected vs counted vs over/short. */
    #[OpenApiResponse(409, 'Already closed (`conflict`)')]
    public function close(ClosePosSessionRequest $request, PosSession $session, CloseRegisterSession $close, DrawerTotals $totals): PosSessionResource
    {
        /** @var User $user */
        $user = $request->user();

        return self::session($close->handle($session, $request->counted(), $request->note(), $user->id), $totals);
    }

    /** `GET /pos/sessions/{session}/catalog` — the grid for this drawer's branch (≤ 60 tiles; search narrows). */
    public function catalog(PosCatalogRequest $request, PosSession $session, PosCatalog $catalog): AnonymousResourceCollection
    {
        return PosCatalogItemResource::collection($catalog->variants($session->location_id, $request->categoryId(), $request->term(), $request->barcode()));
    }

    /** `GET /pos/customers?phone=` — whose number this is (null for a new one), with their open due. */
    public function customer(PosCustomerLookupRequest $request, PosCustomers $customers, PosDues $dues): PosCustomerResource
    {
        $phone = $request->phone();
        $customer = $customers->find($phone);
        if ($customer !== null && PosCustomers::isWalkIn($customer)) {
            $customer = null;
        }
        $openDue = $customer === null ? Money::zero() : $dues->openDue($customer->id);

        return new PosCustomerResource([
            'phone' => $phone,
            'customer' => $customer,
            'open_due' => $openDue,
            'due_limit' => $dues->limit(),
            'due_available' => $dues->available($openDue),
        ]);
    }

    /** `GET /pos/customers/search?q=` — up to 8 customers by name or phone digits, most recent order first, with their due. */
    public function searchCustomers(PosCustomerSearchRequest $request, PosCustomerSearch $search, PosDues $dues): AnonymousResourceCollection
    {
        $customers = $search->search($request->term());
        $open = $dues->openDueFor($customers->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $limit = $dues->limit();

        return PosCustomerMatchResource::collection($customers->map(static function ($customer) use ($open, $limit, $dues): array {
            $due = $open[$customer->id] ?? Money::zero();

            return ['customer' => $customer, 'open_due' => $due, 'due_limit' => $limit, 'due_available' => $dues->available($due)];
        })->values());
    }

    /** `POST /pos/sessions/{session}/quote` — price the cart and read the tenders. Writes nothing. */
    public function quote(QuotePosSaleRequest $request, PosSession $session, PosQuotes $quotes): PosQuoteResource
    {
        return new PosQuoteResource($quotes->quote(
            $session->location_id, $request->lines(), $request->couponCode(), $request->phone(), $request->tenders(),
            $request->customerName(), $request->canSellOnDue(), $request->discount(),
        ));
    }

    /**
     * `POST /pos/sessions/{session}/sales` — Charge. Needs an `Idempotency-Key`; a replay
     * returns the first sale. 201 with the change to give.
     *
     * @response PosSaleResource
     */
    #[OpenApiResponse(409, 'Total changed (`price-changed`), branch stock short (`insufficient-stock`), drawer closed (`conflict`) or key in flight (`request-in-progress`)')]
    #[OpenApiResponse(422, 'A blocker: unsellable line, coupon, phone needed for a discount, tenders short or missing a reference (`validation-error`)')]
    public function charge(ChargePosSaleRequest $request, PosSession $session, ChargeSale $charge): JsonResponse
    {
        $result = OrderProblems::guard($request, static fn () => $charge->handle($request->toData()));
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return (new PosSaleResource($result->load('order')))->response()->setStatusCode(201)
            ->header('Location', '/api/v1/admin/pos/sales/'.$result->id.'/receipt');
    }

    /** `GET /pos/sales/{sale}/receipt` — the printable receipt. */
    public function receipt(PosSale $sale, CompanyDetails $company): PosReceiptResource
    {
        $this->authorize('view', $sale);
        $sale->load(['order.items', 'order.payments', 'order.customer', 'session.location', 'cashier']);

        return new PosReceiptResource(['sale' => $sale, 'company' => $company->snapshot()]);
    }

    /** `GET /pos/sessions/{session}/holds` — parked carts on this drawer, oldest first. */
    public function holds(PosSession $session): AnonymousResourceCollection
    {
        $this->authorize('operate', $session);

        return PosHeldSaleResource::collection(PosHeldSale::query()->where('session_id', $session->id)->whereNull('resumed_at')->whereNull('discarded_at')->orderBy('id')->get());
    }

    /** `POST /pos/sessions/{session}/holds` — "Hold sale". 201. */
    #[OpenApiResponse(409, 'The drawer is closed (`conflict`)')]
    public function hold(HoldPosSaleRequest $request, PosSession $session, HoldSale $hold): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $held = $hold->handle($session, $request->lines(), $request->couponCode(), $request->phone(), $request->label(), $user->id, $request->customerName());

        return (new PosHeldSaleResource($held))->response()->setStatusCode(201);
    }

    /** `POST /pos/holds/{hold}/resume` — take a parked cart back (or discard it). Once only. */
    #[OpenApiResponse(409, 'Already resumed, or the drawer is closed (`conflict`)')]
    public function resume(Request $request, PosHeldSale $hold, ResumeHeldSale $resume): PosHeldSaleResource
    {
        $this->authorize('resume', $hold);
        /** @var User $user */
        $user = $request->user();

        return new PosHeldSaleResource($resume->handle($hold, $user->id));
    }

    /** `DELETE /pos/holds/{hold}` — throw a parked cart away. Once only; open drawer only. 204. */
    #[OpenApiResponse(409, 'Already resumed or discarded, or the drawer is closed (`conflict`)')]
    public function discard(Request $request, PosHeldSale $hold, DiscardHeldSale $discard): Response
    {
        $this->authorize('discard', $hold);
        /** @var User $user */
        $user = $request->user();
        $discard->handle($hold, $user->id);

        return response()->noContent();
    }

    private static function session(PosSession $session, DrawerTotals $totals): PosSessionResource
    {
        $session->loadMissing(['location', 'opener']);

        return new PosSessionResource(['session' => $session, 'totals' => $totals->for($session)]);
    }
}
