<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierQuote;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\DTOs\CourierWebhookEvent;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Orders\Support;
use Tests\Support\CourierFixtures;
use Tests\Support\RoleMatrix;

/*
 * Courier booking queue (Figma B · 07, docs/api/booking-queue.md): the read model behind the
 * tabs and counts, the per-courier preflight reasons, and bulk booking from the queue through
 * the EXISTING bulk endpoint (Idempotency-Key, queued, no courier call inside a transaction —
 * rules 4, 17, 24d). Runs against NullCourierGateway (rule 33a: no merchant account).
 */

const BQ = '/api/v1/admin/shipments/booking-queue';
const BQ_STEADFAST_KEYS = ['api_key' => 'sf-key-12345678', 'secret_key' => 'sf-secret-12345678'];
const BQ_PATHAO_KEYS = ['client_id' => 'c1', 'client_secret' => 'secret-12345678', 'username' => 'ops@example.com', 'password' => 'pw-12345678', 'store_id' => '77'];

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->courier = CourierFixtures::install('steadfast', ['credentials' => BQ_STEADFAST_KEYS]);
});

/** A packed order in Chattogram (district 8, outside Dhaka) with variant weights. */
function bqOrder(array $overrides = [], int $grams = 460, int $quantity = 2): Order
{
    $variant = ProductVariant::factory()->create(['weight_grams' => $grams]);
    $order = Order::factory()->status(OrderStatus::ReadyToShip)->create($overrides + [
        'shipping_name' => 'রাহেলা খাতুন',
        'shipping_district_id' => 8, 'shipping_upazila_id' => null,
        'grand_total' => '2810.00', 'paid_total' => '500.00', 'due_total' => '2310.00',
        'packed_at' => now()->subDay(),
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => $quantity]);

    return $order;
}

function bqShipment(Order $order, ShipmentStatus $status, array $overrides = []): Shipment
{
    return Shipment::query()->create($overrides + [
        'order_id' => $order->id,
        'provider_id' => CourierProvider::query()->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-26-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'status' => $status,
        'weight_grams' => 920, 'item_count' => 2, 'cod_amount' => '2310.00', 'declared_value' => '2810.00',
    ]);
}

function bqActivatePathao(): CourierProvider
{
    CourierFixtures::install('pathao', ['credentials' => BQ_PATHAO_KEYS, 'is_default' => false, 'position' => 1], requiresLocationMapping: true);

    return CourierProvider::query()->where('code', 'pathao')->sole();
}

it('counts each tab from real statuses, and only packed orders with no open shipment are ready', function (): void {
    Support::actingAs(Role::OrderOperator);
    $ready = bqOrder();
    bqOrder(['status' => OrderStatus::Confirmed]);                               // not packed → not in the queue
    $submitted = bqOrder();
    bqShipment($submitted, ShipmentStatus::Submitted, ['pickup_requested_at' => now()]);
    $pending = bqOrder();
    bqShipment($pending, ShipmentStatus::Pending);
    $failedAgain = bqOrder();                                                    // a failed shipment frees the order
    bqShipment($failedAgain, ShipmentStatus::Failed, ['failure_code' => Shipment::FAILURE_REFUSED]);
    $shipped = bqOrder(['status' => OrderStatus::Shipped]);
    bqShipment($shipped, ShipmentStatus::Booked, ['booked_at' => now(), 'consignment_id' => 'SF-1']);
    $yesterday = bqOrder(['status' => OrderStatus::Shipped]);
    bqShipment($yesterday, ShipmentStatus::PickedUp, ['booked_at' => now()->subDays(2), 'consignment_id' => 'SF-2']);

    $json = $this->getJson(BQ)->assertOk()->json();

    expect($json['counts'])->toBe(['ready' => 2, 'submitted' => 2, 'booked_today' => 1, 'failed' => 1, 'unmapped' => 0])
        ->and($json['cutoff_time'])->toMatch('/^\d{2}:\d{2}$/')
        ->and($json['production'])->toBeFalse();

    $rows = $this->getJson(BQ.'/ready')->assertOk()->json('data');
    expect(collect($rows)->pluck('order_id')->all())->toBe([$ready->id, $failedAgain->id]);

    $awaiting = $this->getJson(BQ.'/shipments?filter[tab]=submitted')->assertOk()->json('data');
    expect(collect($awaiting)->pluck('shipment.status')->sort()->values()->all())->toBe(['pending', 'submitted'])
        ->and(collect($awaiting)->pluck('shipment.consignment_id')->filter()->all())->toBe([])
        ->and($awaiting[0]['recipient_name'])->toBe('রাহেলা খাতুন');
    expect($this->getJson(BQ.'/shipments?filter[tab]=booked_today')->json('data'))->toHaveCount(1)
        ->and($this->getJson(BQ.'/shipments?filter[tab]=failed')->json('data.0.shipment.failure_code'))->toBe('provider_refused');
});

it('buckets "booked today" by the Dhaka date, not UTC (rule 11)', function (): void {
    Support::actingAs(Role::OrderOperator);
    // 2026-11-15 00:30 Dhaka = 2026-11-14 18:30 UTC.
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-11-14 18:30:00', 'UTC'));
    bqShipment(bqOrder(['status' => OrderStatus::Shipped]), ShipmentStatus::Booked, ['booked_at' => now()->subMinutes(20), 'consignment_id' => 'A']); // 00:10 Dhaka today
    bqShipment(bqOrder(['status' => OrderStatus::Shipped]), ShipmentStatus::Booked, ['booked_at' => now()->subMinutes(40), 'consignment_id' => 'B']); // 23:50 Dhaka yesterday

    expect($this->getJson(BQ)->json('counts.booked_today'))->toBe(1)
        ->and($this->getJson(BQ)->json('today'))->toBe('2026-11-15');
});

it('describes each courier card from stored data only — no invented balance or webhook health', function (): void {
    Support::actingAs(Role::OrderOperator);
    $steadfast = CourierProvider::query()->where('code', 'steadfast')->sole();
    CourierWebhookReceipt::query()->create([
        'provider' => CourierWebhookReceipt::providerKey('steadfast'), 'external_event_id' => 'e1', 'signature_valid' => true,
        'payload' => ['x' => 1], 'source' => 'webhook', 'processed_at' => now(), 'result' => 'applied',
    ]);
    bqShipment(bqOrder(['status' => OrderStatus::Shipped]), ShipmentStatus::Booked, ['booked_at' => now(), 'consignment_id' => 'SF-9']);

    $cards = collect($this->getJson(BQ)->json('couriers'))->keyBy('code');

    expect($cards['steadfast'])->toMatchArray([
        'id' => $steadfast->id, 'trust' => 'unverified', 'blocked_reason' => null, 'is_default' => true,
        'bookings_count' => 1, 'booked_today' => 1, 'balance' => null, 'balance_checked_at' => null,
    ])->and($cards['steadfast']['webhook'])->toMatchArray(['supported' => true, 'health' => 'healthy'])
        // Seeded Pathao: switched off, no credentials, never called back — said so, not zeroed into "healthy".
        ->and($cards['pathao'])->toMatchArray(['trust' => 'not_connected', 'blocked_reason' => 'inactive'])
        ->and($cards['pathao']['webhook']['health'])->toBe('none');
});

it('gives every ready row its weight, COD and per-courier preflight reason', function (): void {
    Support::actingAs(Role::OrderOperator);
    $pathao = bqActivatePathao();
    $upazila = Upazila::query()->where('district_id', 8)->orderBy('id')->firstOrFail();
    $ok = bqOrder(['shipping_upazila_id' => $upazila->id]);
    $noWeight = bqOrder([], grams: 0);
    $noDistrict = bqOrder(['shipping_district_id' => null]);

    $rows = collect($this->getJson(BQ.'/ready')->assertOk()->json('data'))->keyBy('order_id');
    $reason = fn (int $orderId, int $providerId): ?string => collect($rows[$orderId]['couriers'])->firstWhere('provider_id', $providerId)['blocked_reason'];
    $steadfastId = CourierProvider::query()->where('code', 'steadfast')->value('id');

    expect($rows[$ok->id])->toMatchArray(['weight_grams' => 920, 'weight_missing' => false, 'item_count' => 2, 'cod_amount' => '2310.00', 'zone' => 'outside_dhaka', 'suggested_provider_id' => $steadfastId])
        ->and($rows[$ok->id]['district']['name_bn'])->not->toBeEmpty()
        ->and($rows[$ok->id])->not->toHaveKey('phone');
    // Pathao needs its own zone id and none is mapped → blocked, never guessed (rule 32).
    expect($reason($ok->id, $pathao->id))->toBe('unmapped_location')
        ->and($reason($ok->id, $steadfastId))->toBeNull()
        ->and($reason($noWeight->id, $steadfastId))->toBe('missing_weight')
        ->and($rows[$noWeight->id]['suggested_provider_id'])->toBeNull()
        ->and($reason($noDistrict->id, $steadfastId))->toBe('missing_address');

    // Several zones mapped to one upazila: bulk cannot choose → ambiguous.
    foreach (['11', '12'] as $zone) {
        CourierLocation::query()->create([
            'provider_id' => $pathao->id, 'external_key' => "2:{$zone}:-", 'external_city_id' => '2', 'external_city_name' => 'Chattogram',
            'external_zone_id' => $zone, 'external_zone_name' => "Zone {$zone}", 'district_id' => 8, 'upazila_id' => $upazila->id, 'is_mapped' => true, 'is_active' => true,
        ]);
    }
    $rows = collect($this->getJson(BQ.'/ready')->json('data'))->keyBy('order_id');
    expect(collect($rows[$ok->id]['couriers'])->firstWhere('provider_id', $pathao->id)['blocked_reason'])->toBe('ambiguous_location');
});

it('blocks an unverified courier in production for every row (rule 33a)', function (): void {
    Support::actingAs(Role::OrderOperator);
    config(['couriers.production_environments' => ['testing']]);
    bqOrder();

    $json = $this->getJson(BQ)->json();
    expect(collect($json['couriers'])->firstWhere('code', 'steadfast')['blocked_reason'])->toBe('unverified_in_production')
        ->and($json['production'])->toBeTrue();
    $row = $this->getJson(BQ.'/ready')->json('data.0');
    expect($row['suggested_provider_id'])->toBeNull()
        ->and(collect($row['couriers'])->pluck('blocked_reason')->unique()->values()->all())->toBe(['unverified_in_production', 'inactive']);
});

it('lists unmapped areas per courier and area, and drops one once mapped', function (): void {
    Support::actingAs(Role::OrderOperator);
    $pathao = bqActivatePathao();
    $upazilas = Upazila::query()->where('district_id', 8)->orderBy('id')->limit(2)->get();
    bqOrder(['shipping_upazila_id' => $upazilas[0]->id, 'shipping_upazila' => $upazilas[0]->name]);
    bqOrder(['shipping_upazila_id' => $upazilas[0]->id, 'shipping_upazila' => $upazilas[0]->name]);
    bqOrder(['shipping_upazila_id' => $upazilas[1]->id]);

    $areas = $this->getJson(BQ.'/unmapped')->assertOk()->json('data');
    expect($areas)->toHaveCount(2)
        ->and($areas[0])->toMatchArray(['orders_count' => 2])
        ->and($areas[0]['provider']['code'])->toBe('pathao')
        ->and($areas[0]['upazila']['id'])->toBe($upazilas[0]->id)
        ->and($areas[0]['order_numbers'])->toHaveCount(2)
        ->and($this->getJson(BQ)->json('counts.unmapped'))->toBe(2);

    CourierLocation::query()->create([
        'provider_id' => $pathao->id, 'external_key' => '2:20:-', 'external_city_id' => '2', 'external_city_name' => 'Chattogram',
        'external_zone_id' => '20', 'external_zone_name' => 'Zone 20', 'district_id' => 8, 'upazila_id' => $upazilas[0]->id, 'is_mapped' => true, 'is_active' => true,
    ]);
    expect($this->getJson(BQ.'/unmapped')->json('data'))->toHaveCount(1)
        ->and($this->getJson(BQ)->json('counts.unmapped'))->toBe(1);
});

it('refuses an unlisted or missing filter with a 400 (rule 16)', function (): void {
    Support::actingAs(Role::OrderOperator);

    $this->getJson(BQ.'/shipments')->assertStatus(400);
    $this->getJson(BQ.'/shipments?filter[tab]=delivered')->assertStatus(400);
    $this->getJson(BQ.'/shipments?filter[tab]=failed&filter[status]=failed')->assertStatus(400);
    $this->getJson(BQ.'/ready?filter[zone]=outside_dhaka')->assertStatus(400);
    $this->getJson(BQ.'/ready?sort=-id')->assertStatus(400);
});

it('bulk-books selected rows through the existing endpoint: queued, submitted with no ids, never inside a transaction', function (): void {
    Support::actingAs(Role::OrderOperator);
    // Wrap the fixture driver to record the transaction depth at the moment the courier is called.
    $inner = new NullCourierGateway('steadfast');
    $probe = new class($inner) implements CourierGateway
    {
        /** @var list<int> */
        public array $levels = [];

        public function __construct(private NullCourierGateway $inner) {}

        public function code(): string
        {
            return $this->inner->code();
        }

        public function requiresLocationMapping(): bool
        {
            return false;
        }

        public function supportsBulk(): bool
        {
            return true;
        }

        public function quote(ShipmentRequest $r): CourierQuote
        {
            return $this->inner->quote($r);
        }

        public function book(ShipmentRequest $r): BookingResult
        {
            $this->levels[] = DB::transactionLevel();

            return $this->inner->book($r);
        }

        public function bookMany(array $rs): BulkBookingResult
        {
            $this->levels[] = DB::transactionLevel();

            return $this->inner->bookMany($rs);
        }

        public function status(string $consignmentId): CourierStatusResult
        {
            return $this->inner->status($consignmentId);
        }

        public function verifyWebhook(array $headers, string $rawBody): bool
        {
            return $this->inner->verifyWebhook($headers, $rawBody);
        }

        public function parseWebhook(string $rawBody): ?CourierWebhookEvent
        {
            return $this->inner->parseWebhook($rawBody);
        }
    };
    app(CourierRegistry::class)->register('steadfast', $probe);

    $a = bqOrder();
    $b = bqOrder();
    $ids = collect($this->getJson(BQ.'/ready')->json('data'))->pluck('order_id')->all();
    expect($ids)->toBe([$a->id, $b->id]);

    $baseline = DB::transactionLevel(); // RefreshDatabase's own wrapping transaction
    $key = (string) Illuminate\Support\Str::uuid();
    $this->postJson('/api/v1/admin/shipments/bulk', ['order_ids' => $ids, 'provider' => 'steadfast'])->assertStatus(400);

    $response = $this->postJson('/api/v1/admin/shipments/bulk', ['order_ids' => $ids, 'provider' => 'steadfast'], ['Idempotency-Key' => $key])->assertOk();
    expect($response->json('meta'))->toBe(['requested' => 2, 'failed' => 0]);

    // One bookMany() for both, called with no transaction open beyond the test's own (rule 4).
    expect($inner->bulkBooked)->toHaveCount(1)
        ->and($inner->bulkBooked[0])->toHaveCount(2)
        ->and($inner->booked)->toBe([])
        ->and($probe->levels)->toBe([$baseline]);

    // The rows moved to "Submitted — awaiting ID": no consignment id, order untouched (rule 24d).
    $json = $this->getJson(BQ)->json('counts');
    expect($json['ready'])->toBe(0)->and($json['submitted'])->toBe(2)->and($json['booked_today'])->toBe(0);
    $awaiting = $this->getJson(BQ.'/shipments?filter[tab]=submitted')->json('data');
    expect(collect($awaiting)->pluck('shipment.status')->unique()->all())->toBe(['submitted'])
        ->and(collect($awaiting)->pluck('shipment.consignment_id')->filter()->all())->toBe([])
        ->and($a->refresh()->status)->toBe(OrderStatus::ReadyToShip);

    // A replay with the same key answers the same and books nothing more (rule 17).
    $this->postJson('/api/v1/admin/shipments/bulk', ['order_ids' => $ids, 'provider' => 'steadfast'], ['Idempotency-Key' => $key])->assertOk();
    expect($inner->bulkBooked)->toHaveCount(1)
        ->and(Shipment::query()->count())->toBe(2)
        ->and(DB::table('stock_movements')->where('type', 'sale_out')->count())->toBe(0);
});

it('lets shipments.view read every queue route, nobody else (rule 21)', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BQ));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BQ.'/ready'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BQ.'/shipments?filter[tab]=submitted'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BQ.'/unmapped'));
})->with(RoleMatrix::dataset(Permission::ShipmentsView));
