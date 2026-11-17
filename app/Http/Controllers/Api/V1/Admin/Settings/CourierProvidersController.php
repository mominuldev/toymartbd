<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Fulfilment\Actions\CheckCourierConnection;
use App\Domain\Fulfilment\Actions\UpdateCourierCredentials;
use App\Domain\Fulfilment\Actions\UpdateCourierProviderStatus;
use App\Domain\Fulfilment\Actions\UpdateCourierRateCard;
use App\Domain\Fulfilment\Actions\UpdateFreeDeliveryThreshold;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\UpdateCourierCredentialsRequest;
use App\Http\Requests\Admin\Settings\UpdateCourierProviderRequest;
use App\Http\Requests\Admin\Settings\UpdateCourierRatesRequest;
use App\Http\Requests\Admin\Settings\UpdateDeliverySettingsRequest;
use App\Http\Resources\Admin\CourierConnectionCheckResource;
use App\Http\Resources\Admin\CourierProviderSettingsResource;
use App\Http\Resources\Admin\DeliverySettingsResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Settings → Courier settings & delivery charges (P6, 06-admin-feature-checklist §2.3).
 * Opening the screen needs shipments.manage_rates or settings.manage_integrations; rate
 * cards, on/off/default and the free-delivery threshold need shipments.manage_rates;
 * credentials need settings.manage_integrations. Credentials are write-only.
 */
final class CourierProvidersController extends ApiController
{
    /** `GET /settings/couriers` — every provider, in display order. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourierProvider::class);

        return CourierProviderSettingsResource::collection(CourierProvider::query()->orderBy('position')->orderBy('id')->get());
    }

    /** `PATCH /settings/couriers/{provider}` — on/off, or make default. */
    public function update(UpdateCourierProviderRequest $request, CourierProvider $provider, UpdateCourierProviderStatus $action): CourierProviderSettingsResource
    {
        return new CourierProviderSettingsResource($action->handle($provider, $request->active(), $request->makeDefault()));
    }

    /** `PUT /settings/couriers/{provider}/rates` — the whole rate card. */
    public function rates(UpdateCourierRatesRequest $request, CourierProvider $provider, UpdateCourierRateCard $action): CourierProviderSettingsResource
    {
        return new CourierProviderSettingsResource($action->handle($provider, $request->rates()));
    }

    /** `PUT /settings/couriers/{provider}/credentials` — write-only; blank keeps the saved value. */
    public function credentials(UpdateCourierCredentialsRequest $request, CourierProvider $provider, UpdateCourierCredentials $action): CourierProviderSettingsResource
    {
        return new CourierProviderSettingsResource($action->handle(
            $provider,
            $request->values(),
            $request->clearKeys(),
            $request->webhookSecret(),
            $request->boolean('clear_webhook_secret'),
            $request->user()?->getKey() !== null ? (int) $request->user()->getKey() : null,
        ));
    }

    /**
     * `POST /settings/couriers/{provider}/check` — "Check connection": one read-only call to the
     * courier with the SAVED credentials (Steadfast: balance; Pathao: store list). Books nothing,
     * never verifies the provider (rule 33a). Same permission as the credentials; throttled,
     * because Steadfast locks a key after ten auth failures in five minutes.
     */
    public function check(CourierProvider $provider, CheckCourierConnection $action): CourierConnectionCheckResource
    {
        $this->authorize('updateCredentials', $provider);

        return new CourierConnectionCheckResource($action->handle($provider));
    }

    /** `GET /settings/delivery` — the free-delivery threshold. */
    public function delivery(DeliverySettings $settings): DeliverySettingsResource
    {
        $this->authorize('viewAny', CourierProvider::class);

        return new DeliverySettingsResource(['free_shipping_threshold' => $settings->freeShippingThreshold()?->toDecimal()]);
    }

    /** `PUT /settings/delivery` — null switches free delivery off. */
    public function updateDelivery(UpdateDeliverySettingsRequest $request, UpdateFreeDeliveryThreshold $action, DeliverySettings $settings): DeliverySettingsResource
    {
        $action->handle($request->threshold(), $request->user()?->getKey() !== null ? (int) $request->user()->getKey() : null);
        $settings->refresh();

        return new DeliverySettingsResource(['free_shipping_threshold' => $settings->freeShippingThreshold()?->toDecimal()]);
    }
}
