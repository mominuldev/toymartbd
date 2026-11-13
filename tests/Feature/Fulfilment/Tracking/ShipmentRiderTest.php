<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Ordering\Models\Order;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Fulfilment\Tracking\CourierTrackingKit as Kit;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * The delivery rider on a shipment (owner decision 2026-11-13): staff record the rider's name
 * and phone when the rider calls — neither courier's API returns them — and the "out for
 * delivery" message names the RIDER's number, never our hotline. With no rider recorded the
 * no-rider variant goes; recording one afterwards never resends.
 */

const RIDER_HOTLINE = '09600123456';

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00', 'notifications.hotline' => RIDER_HOTLINE]);
    CourierProvider::query()->where('code', 'steadfast')->update(['supports_webhook' => true]);
});

/** Captures every SMS body sent through the `null` driver arm. */
function riderSms(): object
{
    $fake = new class implements SmsGateway
    {
        /** @var list<array{to: string, body: string, template: string}> */
        public array $sent = [];

        public function send(string $e164Phone, string $body, string $template): SmsResult
        {
            $this->sent[] = ['to' => $e164Phone, 'body' => $body, 'template' => $template];
            $segments = SmsSegments::count($body);

            return SmsResult::accepted('fake-'.count($this->sent), $segments, Money::of('0.26')->times($segments));
        }

        /** @return list<string> */
        public function bodies(string $template): array
        {
            return array_values(array_map(fn (array $s): string => $s['body'], array_filter($this->sent, fn (array $s): bool => $s['template'] === $template)));
        }
    };
    app()->bind(App\Domain\Notification\Drivers\NullSmsGateway::class, fn () => $fake);
    // SMS only (the launch set-up: no WhatsApp account), so the SMS body is what goes out.
    config(['notifications.sms.driver' => 'null', 'notifications.whatsapp.enabled' => false]);

    return $fake;
}

function outForDelivery(Shipment $shipment, $gateway): void
{
    $gateway->willReportStatus((string) $shipment->consignment_id, Kit::result(ShipmentStatus::OutForDelivery, 'out_for_delivery'));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');
}

function preferLocale(Shipment $shipment, string $locale): void
{
    Order::query()->findOrFail($shipment->order_id)->customer->forceFill(['preferred_language' => $locale])->save();
}

/** Switch user after Kit's own manager request: the resolved guard user is cached per app. */
function riderActingAs(Role $role): App\Models\User
{
    app('auth')->forgetGuards();

    return Support::actingAs($role);
}

function riderInvariants(): void
{
    Support::assertStockInvariants();
    expect(Artisan::call('toymart:finance:verify'))->toBe(0);
}

it('records the rider in E.164, audits it with the phone masked, and shows it on the shipment', function (): void {
    $shipment = Kit::bookedShipment(Kit::gateway());
    $user = riderActingAs(Role::OrderOperator);

    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_name' => '  মো. করিম ', 'rider_phone' => '01812345678'])
        ->assertOk()
        ->assertJsonPath('rider.name', 'মো. করিম')
        ->assertJsonPath('rider.phone', '+8801812345678') // order_operator holds customers.view_pii
        ->assertJsonPath('rider.phone_masked', false)
        ->assertJsonPath('rider.source', 'manual');

    $shipment->refresh();
    expect($shipment->rider_phone)->toBe('+8801812345678')
        ->and($shipment->rider_recorded_by)->toBe($user->id)
        ->and($shipment->rider_recorded_at)->not->toBeNull();

    $audit = AuditSensitive::query()->where('action', AuditAction::ShipmentRiderRecorded->value)->sole();
    expect($audit->after)->toBe(['name' => 'মো. করিম', 'phone' => '018****5678', 'source' => 'manual'])
        ->and($audit->before)->toBe(['name' => null, 'phone' => null])
        ->and(json_encode([$audit->before, $audit->after]))->not->toContain('1812345678');

    // The same values again write nothing; both null clears the rider.
    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_name' => 'মো. করিম', 'rider_phone' => '+8801812345678'])->assertOk();
    expect(AuditSensitive::query()->where('action', AuditAction::ShipmentRiderRecorded->value)->count())->toBe(1);
    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_name' => null, 'rider_phone' => null])
        ->assertOk()->assertJsonPath('rider', null);
    expect($shipment->refresh()->rider_phone)->toBeNull()->and($shipment->rider_source)->toBeNull();
    riderInvariants();
});

it('masks the rider phone for a role without customers.view_pii', function (): void {
    $shipment = Kit::bookedShipment(Kit::gateway());
    $shipment->forceFill(['rider_name' => 'Karim', 'rider_phone' => '+8801812345678', 'rider_source' => 'manual', 'rider_recorded_at' => now()])->save();

    riderActingAs(Role::Viewer);
    $this->getJson("/api/v1/admin/shipments/{$shipment->id}")
        ->assertOk()
        ->assertJsonPath('rider.phone', '018****5678')
        ->assertJsonPath('rider.phone_masked', true);
});

it('refuses a phone outside 013–019 and letters (rule 30)', function (string $phone): void {
    $shipment = Kit::bookedShipment(Kit::gateway());
    riderActingAs(Role::OrderOperator);

    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_phone' => $phone])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'rider_phone');
})->with(['011 (Citycell)' => '01112345678', '012' => '01212345678', 'letters' => '01712abc678', 'too short' => '0171234567']);

it('refuses a rider on a shipment no courier holds (409)', function (string $status): void {
    $shipment = Kit::bookedShipment(Kit::gateway());
    Shipment::query()->whereKey($shipment->id)->update(['status' => $status]);
    riderActingAs(Role::OrderOperator);

    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_phone' => '01812345678'])->assertStatus(409);
})->with(['pending', 'failed', 'cancelled']);

it('guards PATCH /shipments/{shipment}/rider with shipments.book', function (Role $role, bool $allowed): void {
    $shipment = Kit::bookedShipment(Kit::gateway());
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_name' => 'Karim', 'rider_phone' => '01812345678']));
})->with(RoleMatrix::dataset(Permission::ShipmentsBook));

it('names the rider in the out-for-delivery SMS — never the hotline — in English', function (): void {
    $sms = riderSms();
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    preferLocale($shipment, 'en');
    riderActingAs(Role::OrderOperator);
    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_name' => 'Karim', 'rider_phone' => '+8801812345678'])->assertOk();

    outForDelivery($shipment, $gateway);

    $bodies = $sms->bodies('order.out_for_delivery');
    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toEndWith('Rider: 01812345678')
        ->and($bodies[0])->not->toContain(RIDER_HOTLINE)
        ->and($sms->bodies('order.out_for_delivery.no_rider'))->toBe([]);

    // The log row keeps the rider's phone masked (rule 20); the real number was read at send time.
    $log = NotificationLog::query()->where('notifiable_id', $shipment->order_id)->where('template', 'order.out_for_delivery')->sole();
    expect($log->payload['rider_phone'])->toBe('018****5678');
    riderInvariants();
});

it('names the rider in the Bangla out-for-delivery SMS too', function (): void {
    $sms = riderSms();
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    preferLocale($shipment, 'bn');
    $shipment->forceFill(['rider_name' => 'করিম', 'rider_phone' => '+8801812345678', 'rider_source' => 'manual', 'rider_recorded_at' => now()])->save();

    outForDelivery($shipment, $gateway);

    $body = $sms->bodies('order.out_for_delivery')[0] ?? '';
    expect($body)->toEndWith('রাইডার: 01812345678')->and($body)->not->toContain(RIDER_HOTLINE);
});

it('sends the no-rider variant when no rider is recorded — no hotline, no literal placeholder (EN + BN)', function (string $locale, string $expected): void {
    $sms = riderSms();
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    preferLocale($shipment, $locale);

    outForDelivery($shipment, $gateway);

    expect($sms->bodies('order.out_for_delivery'))->toBe([]);
    $bodies = $sms->bodies('order.out_for_delivery.no_rider');
    $order = Order::query()->findOrFail($shipment->order_id);
    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toBe(str_replace(['{n}', '{amount}'], [$order->number, App\Domain\Notification\Services\OrderMessageData::amount($order->due_total)], $expected))
        ->and($bodies[0])->not->toContain(RIDER_HOTLINE)
        ->and($bodies[0])->not->toContain('{')
        ->and($bodies[0])->not->toContain('Rider')
        ->and($bodies[0])->not->toContain('রাইডার');
})->with([
    'English' => ['en', 'Toy Mart BD: order {n} is out for delivery today. Please keep BDT {amount} ready.'],
    'Bangla' => ['bn', 'টয় মার্ট বিডি: {n} আজ ডেলিভারি হবে। BDT {amount} হাতে রাখুন।'],
]);

it('does not resend when the rider is recorded after the message went out', function (): void {
    $sms = riderSms();
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    outForDelivery($shipment, $gateway);
    expect($sms->bodies('order.out_for_delivery.no_rider'))->toHaveCount(1);

    riderActingAs(Role::OrderOperator);
    $this->patchJson("/api/v1/admin/shipments/{$shipment->id}/rider", ['rider_phone' => '01812345678'])->assertOk();
    // Even a second "out for delivery" (next day's attempt) sends nothing more.
    $gateway->willReportStatus((string) $shipment->consignment_id, Kit::result(ShipmentStatus::InTransit, 'in_transit'));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');
    outForDelivery($shipment, $gateway);

    expect($sms->bodies('order.out_for_delivery'))->toBe([])
        ->and($sms->bodies('order.out_for_delivery.no_rider'))->toHaveCount(1)
        ->and(NotificationLog::query()->where('notifiable_id', $shipment->order_id)->whereIn('template', ['order.out_for_delivery', 'order.out_for_delivery.no_rider'])->count())->toBe(1);
    riderInvariants();
});

it('passes the rider phone as the WhatsApp parameter, and the no-rider template without it', function (): void {
    $wa = new class implements App\Domain\Notification\Contracts\WhatsAppGateway
    {
        /** @var list<array{template: string, parameters: list<string>}> */
        public array $sent = [];

        public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): App\Domain\Notification\DTOs\WhatsAppResult
        {
            $this->sent[] = ['template' => $providerTemplate, 'parameters' => $parameters];

            return App\Domain\Notification\DTOs\WhatsAppResult::accepted('wamid.'.count($this->sent), Money::of('0.45'));
        }
    };
    app()->instance(App\Domain\Notification\Contracts\WhatsAppGateway::class, $wa);
    config(['notifications.whatsapp.enabled' => true]);
    $gateway = Kit::gateway();

    $with = Kit::bookedShipment($gateway);
    $with->forceFill(['rider_phone' => '+8801812345678', 'rider_source' => 'manual', 'rider_recorded_at' => now()])->save();
    outForDelivery($with, $gateway);
    $without = Kit::bookedShipment($gateway, consignment: 'CN-2002');
    outForDelivery($without, $gateway);

    $sent = collect($wa->sent)->keyBy('template');
    expect($sent['order_out_for_delivery']['parameters'][2] ?? null)->toBe('01812345678')
        ->and($sent['order_out_for_delivery_no_rider']['parameters'] ?? null)->toHaveCount(2)
        ->and(implode(' ', $sent['order_out_for_delivery_no_rider']['parameters']))->not->toContain(RIDER_HOTLINE);
});

it('stores measured EN + BN segment counts for the no-rider variant', function (): void {
    $sms = NotificationTemplate::for('order.out_for_delivery.no_rider', App\Domain\Notification\Enums\NotificationChannel::Sms);
    $whatsapp = NotificationTemplate::for('order.out_for_delivery.no_rider', App\Domain\Notification\Enums\NotificationChannel::WhatsApp);

    expect($sms)->not->toBeNull()
        ->and($sms->segments_en)->toBe(1)
        ->and($sms->encoding_en)->toBe('gsm7')
        ->and($sms->segments_bn)->toBe(1) // 66 UCS-2 characters with the sample: one segment
        ->and($sms->encoding_bn)->toBe('ucs2')
        ->and($sms->chars_bn)->toBeGreaterThan(0)
        ->and($whatsapp?->provider_template)->toBe('order_out_for_delivery_no_rider')
        // The variant has no rider line, so it may not reference the rider's phone.
        ->and($sms->body_en.$sms->body_bn.$whatsapp?->body_en.$whatsapp?->body_bn)->not->toContain('{rider_phone}')
        ->and($sms->body_en.$sms->body_bn)->not->toContain('{hotline}');
});
