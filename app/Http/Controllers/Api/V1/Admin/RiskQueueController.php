<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Services\CourierHistoryLookup;
use App\Domain\Ordering\Actions\RejectHeldOrder;
use App\Domain\Ordering\Actions\VerifyHeldOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RiskLevel;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Actions\LogVerificationCall;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Domain\Risk\Services\PhoneHistoryReader;
use App\Domain\Risk\Services\RiskScorer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Risk\LogVerificationCallRequest;
use App\Http\Requests\Admin\Risk\RejectHeldOrderRequest;
use App\Http\Requests\Admin\Risk\VerifyHeldOrderRequest;
use App\Http\Resources\Admin\Risk\HeldOrder;
use App\Http\Resources\Admin\Risk\HeldOrderDetailResource;
use App\Http\Resources\Admin\Risk\RiskQueueItemResource;
use App\Http\Resources\Admin\Risk\RiskVerificationCallResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The verification queue (Figma B · 05 241:36206, checklist §2.4): orders the risk check put
 * ON HOLD, waiting for a person. Actions: verified (release), log a call, cancel with a reason
 * — and nothing else. Risk never moves an order by itself (rules 23, 24b); every move here is
 * a staff member's, through an Ordering Action.
 */
final class RiskQueueController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /risk/queue` — held orders waiting for verification, oldest first (first in, first
     * called). `filter[risk_level]` (low|medium|high). Anything else is a 400. Needs risk.view.
     */
    #[QueryParameter('filter[risk_level]', 'low|medium|high', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomerRiskProfile::class);

        $page = QueryBuilder::for(self::held(), $request)
            ->allowedFilters(AllowedFilter::callback('risk_level', static function (Builder $query, mixed $value): void {
                $level = is_string($value) ? RiskLevel::tryFrom($value) : null;
                if ($level === null) {
                    throw new BadRequestHttpException('filter[risk_level] must be one of: low, medium, high.');
                }
                $query->where('risk_level', $level->value);
            }))
            ->orderBy('placed_at')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<Order> $orders */
        $orders = $page->items();
        $rows = self::rows($orders);
        $page->setCollection(collect($rows));

        return RiskQueueItemResource::collection($page)->additional(['meta' => ['count' => self::held()->count()]]);
    }

    /**
     * `GET /risk/orders/{order}` — the side panel: the row, the call log, and the phone's
     * lookup (our score and, separately, each courier's record — rule 24). Needs risk.view.
     * The courier lookup may call a provider; it runs outside any transaction (rule 4).
     */
    public function show(Order $order, CourierHistoryLookup $courier, PhoneHistoryReader $history, RiskScorer $scorer): HeldOrderDetailResource
    {
        $this->authorize('viewAny', CustomerRiskProfile::class);

        [$row] = self::rows([$order]);
        $calls = RiskVerificationCall::query()->where('order_id', $order->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return new HeldOrderDetailResource($row, $calls, RiskPhoneController::view($order->shipping_phone, $courier, $history, $scorer));
    }

    /** `POST /risk/orders/{order}/verify` — verified by phone: hold released (on_hold → pending). Needs risk.verify. */
    #[OpenApiResponse(409, 'The order is not waiting for verification (`conflict`)')]
    public function verify(VerifyHeldOrderRequest $request, Order $order, VerifyHeldOrder $verify): JsonResponse
    {
        $order = $verify->handle($order, $request->user()?->id, $request->note());

        return response()->json(['id' => $order->id, 'status' => $order->status->value, 'verification_status' => $order->verification_status->value]);
    }

    /**
     * `POST /risk/orders/{order}/reject` — a PERSON cancels the held order with a reason;
     * verification is marked failed. Needs risk.verify and orders.cancel.
     */
    #[OpenApiResponse(409, 'The order is not waiting for verification (`conflict`)')]
    public function reject(RejectHeldOrderRequest $request, Order $order, RejectHeldOrder $reject): JsonResponse
    {
        $order = $reject->handle($order, $request->reason(), $request->note(), $request->user()?->id);

        return response()->json(['id' => $order->id, 'status' => $order->status->value, 'verification_status' => $order->refresh()->verification_status->value]);
    }

    /** `POST /risk/orders/{order}/calls` — log a call outcome. Never moves the order. Needs risk.verify. */
    public function logCall(LogVerificationCallRequest $request, Order $order, LogVerificationCall $log): JsonResponse
    {
        $call = $log->handle($order->id, $order->shipping_phone, $request->outcome(), $request->note(), $request->user()?->id);

        return (new RiskVerificationCallResource($call))->response()->setStatusCode(201);
    }

    /** @return Builder<Order> */
    private static function held(): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::OnHold->value)
            ->where('verification_status', VerificationStatus::Pending->value);
    }

    /**
     * @param  list<Order>  $orders
     * @return list<HeldOrder>
     */
    private static function rows(array $orders): array
    {
        if ($orders === []) {
            return [];
        }
        $phones = array_values(array_unique(array_map(static fn (Order $o): string => $o->shipping_phone, $orders)));
        $profiles = CustomerRiskProfile::query()->whereIn('phone', $phones)->get()->keyBy('phone');
        $ids = array_map(static fn (Order $o): int => $o->id, $orders);
        $counts = RiskVerificationCall::query()->whereIn('order_id', $ids)
            ->selectRaw('order_id, COUNT(*) AS n, MAX(id) AS last_id')->groupBy('order_id')->get()->keyBy('order_id');
        $lastIds = $counts->pluck('last_id')->filter()->all();
        $outcomes = RiskVerificationCall::query()->whereIn('id', $lastIds)->get()->keyBy('order_id');

        return array_map(static function (Order $o) use ($profiles, $counts, $outcomes): HeldOrder {
            $count = $counts->get($o->id);
            $last = $outcomes->get($o->id);
            $profile = $profiles->get($o->shipping_phone);

            return new HeldOrder(
                $o,
                $profile instanceof CustomerRiskProfile ? $profile : null,
                $count !== null && is_numeric($count->getAttribute('n')) ? (int) $count->getAttribute('n') : 0,
                $last instanceof RiskVerificationCall ? $last->outcome->value : null,
            );
        }, $orders);
    }
}
