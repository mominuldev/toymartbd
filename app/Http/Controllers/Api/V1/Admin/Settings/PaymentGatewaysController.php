<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Payment\Actions\CheckPaymentGatewayConnection;
use App\Domain\Payment\Actions\UpdatePaymentGatewaySettings;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Admin\Settings\UpdatePaymentGatewayRequest;
use App\Http\Resources\Admin\PaymentGatewayConnectionCheckResource;
use App\Http\Resources\Admin\PaymentGatewayResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Integrations › Payments (owner request 2026-11-19): the online gateways SSLCommerz and
 * PayStation — a sandbox and a live key set each, the mode in use, on/off and "Check
 * connection". Everything needs settings.manage_integrations. Secret keys are write-only
 * (rule 20). Verifying a gateway for production stays an owner CLI step
 * (`toymart:payments:gateway --verify`); there is no endpoint for it.
 */
final class PaymentGatewaysController extends ApiController
{
    /** `GET /settings/payment-gateways` — every managed gateway, in display order. */
    public function index(OnlineGateways $gateways): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentProvider::class);

        $rows = PaymentProvider::query()
            ->whereIn('code', array_map(static fn (PaymentMethod $m): string => $m->value, GatewayCredentials::methods()))
            ->orderBy('position')->orderBy('id')->get();

        return PaymentGatewayResource::collection($rows->map(static fn (PaymentProvider $row): array => self::view($row, $gateways))->all());
    }

    /**
     * `PUT /settings/payment-gateways/{code}` — on/off, mode, and either mode's keys. A blank key
     * keeps the saved value; `clear` removes one. 422 when switching to live without a complete
     * live set, or switching on without a complete set for the mode.
     */
    public function update(UpdatePaymentGatewayRequest $request, UpdatePaymentGatewaySettings $action, OnlineGateways $gateways): PaymentGatewayResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $row = $action->handle(
            $request->gateway(),
            $request->active(),
            $request->mode(),
            $request->values(),
            $request->clearKeys(),
            is_int($userId) ? $userId : null,
        );

        return new PaymentGatewayResource(self::view($row, $gateways));
    }

    /**
     * `POST /settings/payment-gateways/{code}/check` — "Check connection": one read-only call to
     * the gateway with the keys saved for its CURRENT mode. Takes no money, verifies nothing.
     * Throttled. 422 `connection-check-unsupported` when the gateway's driver cannot check keys.
     */
    public function check(Request $request, string $code, CheckPaymentGatewayConnection $action): PaymentGatewayConnectionCheckResource|JsonResponse
    {
        $this->authorize('checkConnection', PaymentProvider::class);
        $method = PaymentMethod::tryFrom($code);
        if ($method === null || ! in_array($method, GatewayCredentials::methods(), true)) {
            throw new NotFoundHttpException;
        }

        $result = $action->handle($method);
        if ($result === null) {
            return ProblemDetails::make(ProblemType::ConnectionCheckUnsupported)->toResponse($request);
        }

        return new PaymentGatewayConnectionCheckResource($result);
    }

    /** @return array{gateway: PaymentProvider, method: PaymentMethod, status: array{enabled: bool, reason: string|null}} */
    private static function view(PaymentProvider $row, OnlineGateways $gateways): array
    {
        $method = PaymentMethod::from($row->code);
        $status = $gateways->status($method);

        return ['gateway' => $row, 'method' => $method, 'status' => ['enabled' => $status['enabled'], 'reason' => $status['reason']]];
    }
}
