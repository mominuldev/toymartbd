<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Listeners\RevalidateDeliveryData;
use App\Domain\Fulfilment\Actions\UpdateCourierRateCard;
use App\Domain\Fulfilment\Actions\UpdateFreeDeliveryThreshold;
use App\Domain\Fulfilment\Events\DeliveryReferenceDataChanged;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Storefront `delivery` tag (docs/api/revalidation.md): every admin write that changes the
 * published rate table or the district/upazila selectors purges it, queued after commit.
 * Writes: rate card, courier on/off/default, free-delivery threshold.
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    (new DeliverySeeder)->run();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    $this->providerId = static fn (string $code): int => (int) CourierProvider::query()->where('code', $code)->value('id');
    $this->rateCard = static fn (array $overrides = []): array => array_merge([
        'base_charge_dhaka' => '60.00', 'base_charge_suburb' => '110.00', 'base_charge_outside' => '140.00',
        'included_weight_grams' => 1000, 'per_kg_charge' => '20.00', 'cod_fee_percent' => '1.00', 'return_charge' => '70.00',
    ], $overrides);
});

$deliveryPurge = static fn (RevalidateStorefrontTags $job): bool => $job->tags === ['delivery'] && $job->afterCommit === true;

it('purges `delivery` after a rate card save, and not for an unchanged one or a courier-billing-only change', function () use ($deliveryPurge): void {
    $id = ($this->providerId)('steadfast');
    $this->putJson("/api/v1/admin/settings/couriers/{$id}/rates", ($this->rateCard)())->assertOk();

    Queue::fake();
    $this->putJson("/api/v1/admin/settings/couriers/{$id}/rates", ($this->rateCard)(['base_charge_outside' => '150.00']))->assertOk();
    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, $deliveryPurge);
    Queue::assertPushed(RevalidateStorefrontTags::class, 1);

    $this->putJson("/api/v1/admin/settings/couriers/{$id}/rates", ($this->rateCard)(['base_charge_outside' => '150.00']))->assertOk();
    $this->putJson("/api/v1/admin/settings/couriers/{$id}/rates", ($this->rateCard)(['base_charge_outside' => '150.00', 'cod_fee_percent' => '1.50', 'return_charge' => '80.00']))->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 1);

    $this->putJson("/api/v1/admin/settings/couriers/{$id}/rates", ($this->rateCard)(['base_charge_outside' => '150.00', 'per_kg_charge' => '25.00']))->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
});

it('purges `delivery` when a courier is switched on or made default, and not for a no-op', function () use ($deliveryPurge): void {
    Queue::fake();
    $pathao = ($this->providerId)('pathao');

    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao}", ['is_active' => true])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 1);

    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao}", ['is_default' => true])->assertOk()->assertJsonPath('is_default', true);
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, $deliveryPurge);

    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao}", ['is_default' => true])->assertOk();
    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao}", ['is_active' => true])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
});

it('purges `delivery` when the free-delivery threshold is saved or switched off', function () use ($deliveryPurge): void {
    Queue::fake();

    $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => '2500.00'])->assertOk();
    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, $deliveryPurge);

    $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => null])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
});

it('sends nothing for a credentials save — the storefront shows none of it', function (): void {
    Queue::fake();
    $steadfast = ($this->providerId)('steadfast');

    $this->putJson("/api/v1/admin/settings/couriers/{$steadfast}/credentials", ['credentials' => ['api_key' => 'sf-key-0000111122223333']])->assertOk();

    Queue::assertNotPushed(RevalidateStorefrontTags::class);
});

it('holds the purge until the outer transaction commits, and drops it on rollback', function (): void {
    Queue::fake();
    $provider = CourierProvider::query()->where('code', 'steadfast')->firstOrFail();

    DB::beginTransaction();
    app(UpdateCourierRateCard::class)->handle($provider, ['base_charge_dhaka' => '65.00']);
    app(UpdateFreeDeliveryThreshold::class)->handle(Money::of('3000.00'), null);
    Queue::assertNotPushed(RevalidateStorefrontTags::class);
    DB::rollBack();

    Queue::assertNotPushed(RevalidateStorefrontTags::class);

    DB::beginTransaction();
    app(UpdateFreeDeliveryThreshold::class)->handle(Money::of('3000.00'), null);
    DB::commit();

    Queue::assertPushed(RevalidateStorefrontTags::class, 1);
});

it('wires DeliveryReferenceDataChanged to the Content listener as an after-commit event', function (): void {
    Event::fake([DeliveryReferenceDataChanged::class]);

    app(UpdateFreeDeliveryThreshold::class)->handle(Money::of('2000.00'), null);

    Event::assertListening(DeliveryReferenceDataChanged::class, RevalidateDeliveryData::class);
    Event::assertDispatched(DeliveryReferenceDataChanged::class, fn (DeliveryReferenceDataChanged $e): bool => $e->reason === 'free_shipping_threshold');
    expect(new DeliveryReferenceDataChanged('x'))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});
