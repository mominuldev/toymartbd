<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Drivers\Pathao\PathaoException;
use App\Domain\Fulfilment\Drivers\Pathao\PathaoGateway;
use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PathaoFixtures as PF;

/*
 * Pathao driver against REAL sandbox responses (tests/Fixtures/Pathao/, captured
 * 2026-11-13; `documented.*` files are the research shapes, not observed). No test reaches
 * the network.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    PF::configure();
});

function pathao(): PathaoGateway
{
    return app(CourierRegistry::class)->for('pathao');
}

/** @return list<Request> */
function pathaoRequests(string $path): array
{
    return Http::recorded(static fn (Request $r): bool => str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?: '', $path))
        ->map(static fn (array $pair): Request => $pair[0])->values()->all();
}

it('is registered for the pathao code and needs location mapping', function (): void {
    expect(pathao())->toBeInstanceOf(PathaoGateway::class)
        ->and(pathao()->requiresLocationMapping())->toBeTrue()
        ->and(pathao()->supportsBulk())->toBeTrue();
});

describe('status map', function (): void {
    it('normalises every documented Pathao status', function (string $raw): void {
        expect(PathaoGateway::normalise($raw))->toBeInstanceOf(ShipmentStatus::class);
    })->with(PathaoGateway::documentedStatuses());

    it('maps the lifecycle to the shipment states', function (): void {
        expect(PathaoGateway::normalise('Pending'))->toBe(ShipmentStatus::Booked)
            ->and(PathaoGateway::normalise('order.picked'))->toBe(ShipmentStatus::PickedUp)
            ->and(PathaoGateway::normalise('At_the_Sorting_HUB'))->toBe(ShipmentStatus::InTransit)
            ->and(PathaoGateway::normalise('order.assigned-for-delivery'))->toBe(ShipmentStatus::OutForDelivery)
            ->and(PathaoGateway::normalise('order.delivered'))->toBe(ShipmentStatus::Delivered)
            ->and(PathaoGateway::normalise('order.partial-delivery'))->toBe(ShipmentStatus::PartiallyDelivered)
            ->and(PathaoGateway::normalise('Return'))->toBe(ShipmentStatus::Returned)
            ->and(PathaoGateway::normalise('order.pickup-cancelled'))->toBe(ShipmentStatus::Cancelled)
            ->and(PathaoGateway::normalise('order.on-hold'))->toBe(ShipmentStatus::OnHold);
    });

    it('has no map key that no documented status reaches', function (): void {
        $reached = array_unique(array_map(PathaoGateway::key(...), PathaoGateway::documentedStatuses()));
        expect(array_diff(array_keys(PathaoGateway::statusMap()), $reached))->toBe([]);
    });

    it('refuses an unknown status loudly instead of guessing', function (): void {
        PathaoGateway::normalise('Teleported');
    })->throws(PathaoException::class, 'Teleported');
});

describe('token', function (): void {
    it('issues one token with the password grant and reuses it from the cache', function (): void {
        Http::fake([
            PF::HOST.'/issue-token' => PF::response('token.issued'),
            PF::HOST.'/orders/*' => PF::response('order.info'),
        ]);

        pathao()->status('DS131126MTS7DD');
        pathao()->status('DS131126MTS7DD');

        $grants = pathaoRequests('/issue-token');
        expect($grants)->toHaveCount(1)
            ->and($grants[0]['grant_type'])->toBe('password')
            ->and($grants[0]['client_id'])->toBe('test-client');
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/info') && $r->hasHeader('Authorization', 'Bearer fixture-access-token-1'));
    });

    it('refreshes on a 401 (the sandbox revokes the old token on refresh) and retries once', function (): void {
        Http::fake([
            PF::HOST.'/issue-token' => Http::sequence()->push(PF::raw('token.issued'), 200)->push(PF::raw('token.refreshed'), 200),
            PF::HOST.'/orders/*' => Http::sequence()->push(PF::raw('auth.token-revoked'), 401)->push(PF::raw('order.info'), 200),
        ]);

        $result = pathao()->status('DS131126MTS7DD');

        expect($result->status)->toBe(ShipmentStatus::Booked);
        $grants = pathaoRequests('/issue-token');
        expect($grants)->toHaveCount(2)
            ->and($grants[1]['grant_type'])->toBe('refresh_token')
            ->and($grants[1]['refresh_token'])->toBe('fixture-refresh-token-1');
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/info') && $r->hasHeader('Authorization', 'Bearer fixture-access-token-2'));
    });

    it('treats a 400 verify-auth-token (token missing) as an auth failure too', function (): void {
        Http::fake([
            PF::HOST.'/issue-token' => Http::sequence()->push(PF::raw('token.issued'), 200)->push(PF::raw('token.refreshed'), 200),
            PF::HOST.'/orders/*' => Http::sequence()->push(PF::raw('auth.token-missing'), 400)->push(PF::raw('order.info'), 200),
        ]);

        expect(pathao()->status('DS131126MTS7DD')->rawStatus)->toBe('Pending');
    });

    it('falls back to the password grant when the refresh is refused', function (): void {
        Http::fake([
            PF::HOST.'/issue-token' => Http::sequence()
                ->push(PF::raw('token.issued'), 200)
                ->push(PF::raw('token.invalid-credentials'), 400)
                ->push(PF::raw('token.refreshed'), 200),
            PF::HOST.'/orders/*' => Http::sequence()->push(PF::raw('auth.token-invalid'), 401)->push(PF::raw('order.info'), 200),
        ]);

        pathao()->status('DS131126MTS7DD');

        expect(array_map(static fn (Request $r): mixed => $r['grant_type'], pathaoRequests('/issue-token')))->toBe(['password', 'refresh_token', 'password']);
    });

    it('gives up after one re-authentication, without leaking a credential', function (): void {
        Http::fake([
            PF::HOST.'/issue-token' => PF::response('token.issued'),
            PF::HOST.'/orders/*' => PF::response('auth.token-invalid', 401),
        ]);

        try {
            pathao()->status('DS131126MTS7DD');
            $this->fail('expected an auth failure');
        } catch (PathaoException $e) {
            expect($e->reason)->toBe(PathaoException::AUTH)
                ->and($e->getMessage())->not->toContain('test-password')
                ->and($e->getMessage())->not->toContain('test-client-secret')
                ->and($e->getMessage())->not->toContain('fixture-access-token');
        }
    });

    it('reports bad credentials as an auth failure', function (): void {
        Http::fake([PF::HOST.'/issue-token' => PF::response('token.invalid-credentials', 400)]);

        pathao()->status('DS131126MTS7DD');
    })->throws(PathaoException::class, 'refused our credentials');
});

describe('book', function (): void {
    beforeEach(function (): void {
        Http::fake([
            PF::HOST.'/issue-token' => PF::response('token.issued'),
        ]);
    });

    it('books with the mapped city and zone, omitting a null area', function (): void {
        Http::fake([PF::HOST.'/orders' => PF::response('order.create.success')]);

        $result = pathao()->book(PF::request());

        expect($result->accepted)->toBeTrue()
            ->and($result->consignmentId)->toBe('DS131126MTS7DD')
            ->and($result->providerStatusRaw)->toBe('Pending')
            ->and($result->charge?->equals(Money::of('60')))->toBeTrue();

        $sent = pathaoRequests('/orders')[0]->data();
        expect($sent)->toMatchArray([
            'store_id' => 39,
            'merchant_order_id' => 'TMB-26-00042',
            'recipient_phone' => '01712046318',
            'recipient_city' => 1,
            'recipient_zone' => 298,
            'delivery_type' => 48,
            'item_type' => 2,
            'item_quantity' => 2,
            'item_weight' => 1.5,
            'amount_to_collect' => 500,
            'special_instruction' => 'Call before delivery',
        ])->and($sent)->not->toHaveKey('recipient_area')
            ->and($sent['recipient_address'])->toContain('Landmark: মিরপুর 10 গোলচত্বরের কাছে');
    });

    it('sends the area when the mapping is area-level', function (): void {
        Http::fake([PF::HOST.'/orders' => PF::response('order.create.success')]);

        pathao()->book(PF::request(new CourierLocationRef('1', '298', '3069')));

        expect(pathaoRequests('/orders')[0]['recipient_area'])->toBe(3069);
    });

    it('is blocked, with no call at all, when the address is unmapped', function (): void {
        $result = pathao()->book(PF::request(null));

        expect($result->accepted)->toBeFalse()
            ->and($result->error)->toContain('never guess');
        Http::assertNothingSent();
    });

    it('returns a validation refusal with Pathao\'s field errors', function (): void {
        Http::fake([PF::HOST.'/orders' => PF::response('order.create.validation', 422)]);

        $result = pathao()->book(PF::request());

        expect($result->accepted)->toBeFalse()
            ->and($result->error)->toContain('Please fix the given errors')
            ->and($result->error)->toContain('recipient phone is not a valid phone number');
    });

    it('throws a transport failure on a timeout so the job retries', function (): void {
        Http::fake([PF::HOST.'/orders' => static fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        try {
            pathao()->book(PF::request());
            $this->fail('expected a transport failure');
        } catch (PathaoException $e) {
            expect($e->reason)->toBe(PathaoException::TRANSPORT);
        }
    });

    it('throws a transport failure on a 5xx', function (): void {
        Http::fake([PF::HOST.'/orders' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        pathao()->book(PF::request());
    })->throws(PathaoException::class, 'HTTP 502');

    it('throws "malformed" — not a refusal — when a 2xx carries no consignment id', function (): void {
        Http::fake([PF::HOST.'/orders' => Http::response('{"message":"ok","type":"success","code":200,"data":{}}', 200)]);

        try {
            pathao()->book(PF::request());
            $this->fail('expected malformed');
        } catch (PathaoException $e) {
            expect($e->reason)->toBe(PathaoException::MALFORMED);
        }
    });

    it('throws "malformed" on a non-JSON 2xx', function (): void {
        Http::fake([PF::HOST.'/orders' => Http::response('<html>maintenance</html>', 200)]);

        pathao()->book(PF::request());
    })->throws(PathaoException::class, 'could not read');

    it('refuses a parcel over 10 kg and a COD amount with poisha before calling', function (): void {
        expect(pathao()->book(PF::request(grams: 10_500))->error)->toContain('10 kg')
            ->and(pathao()->book(PF::request(cod: '499.50'))->error)->toContain('whole taka');
        Http::assertNothingSent();
    });

    it('sends the 0.5 kg minimum for a light parcel', function (): void {
        Http::fake([PF::HOST.'/orders' => PF::response('order.create.success')]);

        pathao()->book(PF::request(grams: 120));

        expect(pathaoRequests('/orders')[0]['item_weight'])->toBe(0.5);
    });
});

describe('bulk', function (): void {
    beforeEach(function (): void {
        Http::fake([PF::HOST.'/issue-token' => PF::response('token.issued')]);
    });

    it('submits a batch and reports refs only — no consignment ids (rule 24d)', function (): void {
        Http::fake([PF::HOST.'/orders/bulk' => PF::response('documented.order.bulk.accepted', 202)]);

        $result = pathao()->bookMany([PF::request(), PF::request(new CourierLocationRef('1', '1070', null))]);

        expect($result->submitted)->toBeTrue()
            ->and($result->refs)->toBe(['TMB-26-00042', 'TMB-26-00042'])
            ->and(pathaoRequests('/orders/bulk')[0]['orders'])->toHaveCount(2);
    });

    it('submits nothing when any parcel in the batch is unmapped', function (): void {
        $result = pathao()->bookMany([PF::request(), PF::request(null)]);

        expect($result->submitted)->toBeFalse()->and($result->error)->toContain('TMB-26-00042');
        Http::assertNothingSent();
    });

    it('reports a validation refusal for the batch', function (): void {
        Http::fake([PF::HOST.'/orders/bulk' => PF::response('order.bulk.validation', 422)]);

        $result = pathao()->bookMany([PF::request()]);

        expect($result->submitted)->toBeFalse()->and($result->error)->toContain('orders.0.recipient_phone');
    });
});

describe('quote and status', function (): void {
    beforeEach(function (): void {
        Http::fake([PF::HOST.'/issue-token' => PF::response('token.issued')]);
    });

    it('quotes from the price plan with the COD fee at Pathao\'s rate', function (): void {
        Http::fake([PF::HOST.'/merchant/price-plan' => PF::response('price-plan.success')]);

        $quote = pathao()->quote(PF::request(cod: '1500.00'));

        expect($quote->charge->equals(Money::of('90')))->toBeTrue()
            ->and($quote->codFee->equals(Money::of('15.00')))->toBeTrue();
        expect(pathaoRequests('/merchant/price-plan')[0]->data())->toMatchArray(['store_id' => 39, 'recipient_city' => 1, 'recipient_zone' => 298, 'item_weight' => 1.5])
            ->not->toHaveKey('recipient_area');
    });

    it('cannot quote an unmapped address', function (): void {
        pathao()->quote(PF::request(null));
    })->throws(PathaoException::class, 'never guess');

    it('reads the order info snapshot with Dhaka time', function (): void {
        Http::fake([PF::HOST.'/orders/*' => PF::response('order.info')]);

        $status = pathao()->status('DS131126MTS7DD');

        expect($status->status)->toBe(ShipmentStatus::Booked)
            ->and($status->rawStatus)->toBe('Pending')
            ->and($status->consignmentId)->toBe('DS131126MTS7DD')
            ->and($status->occurredAt?->utc()->format('Y-m-d H:i'))->toBe('2026-11-13 05:37');
    });

    it('throws not-found for an unknown consignment', function (): void {
        Http::fake([PF::HOST.'/orders/*' => PF::response('order.info.not-found', 404)]);

        pathao()->status('NOSUCH');
    })->throws(PathaoException::class, 'no consignment');
});

describe('webhook', function (): void {
    it('verifies the shared-secret header in constant time, case-insensitively', function (): void {
        $body = PF::raw('documented.webhook.order-delivered');

        expect(pathao()->verifyWebhook(['x-pathao-signature' => [PF::WEBHOOK_SECRET]], $body))->toBeTrue()
            ->and(pathao()->verifyWebhook(['X-PATHAO-Signature' => PF::WEBHOOK_SECRET], $body))->toBeTrue()
            ->and(pathao()->verifyWebhook(['x-pathao-signature' => ['wrong']], $body))->toBeFalse()
            ->and(pathao()->verifyWebhook([], $body))->toBeFalse();
    });

    it('rejects every webhook while no secret is configured', function (): void {
        App\Domain\Fulfilment\Models\CourierProvider::query()->where('code', 'pathao')->update(['webhook_secret' => null]);

        expect(pathao()->verifyWebhook(['x-pathao-signature' => ['']], '{}'))->toBeFalse();
    });

    it('parses an order event into a trigger to re-query, with a stable event id', function (): void {
        $event = pathao()->parseWebhook(PF::raw('documented.webhook.order-delivered'));

        expect($event?->consignmentId)->toBe('DS131126MTS7DD')
            ->and($event?->merchantOrderRef)->toBe('TMBD-SANDBOX-0001')
            ->and($event?->rawStatus)->toBe('order.delivered')
            ->and($event?->eventId)->toBe(pathao()->parseWebhook(PF::raw('documented.webhook.order-delivered'))?->eventId);
    });

    it('returns null for the handshake, store events and malformed bodies', function (): void {
        expect(pathao()->parseWebhook(PF::raw('documented.webhook.handshake')))->toBeNull()
            ->and(PathaoGateway::isHandshake(PF::raw('documented.webhook.handshake')))->toBeTrue()
            ->and(pathao()->parseWebhook(PF::raw('documented.webhook.store-created')))->toBeNull()
            ->and(pathao()->parseWebhook('not json'))->toBeNull()
            ->and(pathao()->parseWebhook('{"event":"order.delivered"}'))->toBeNull();
    });
});

it('falls back to env credentials only in sandbox mode', function (): void {
    App\Domain\Fulfilment\Models\CourierProvider::query()->where('code', 'pathao')->update(['credentials' => null]);
    config(['couriers.pathao' => [...config('couriers.pathao'), 'client_id' => 'env-client', 'client_secret' => 's', 'username' => 'u', 'password' => 'p', 'store_id' => '39']]);

    expect(App\Domain\Fulfilment\Drivers\Pathao\PathaoCredentials::resolve()?->clientId)->toBe('env-client');

    App\Domain\Fulfilment\Models\CourierProvider::query()->where('code', 'pathao')->update(['settings' => json_encode(['environment' => 'production'])]);
    config(['couriers.pathao.sandbox' => false]);

    expect(App\Domain\Fulfilment\Drivers\Pathao\PathaoCredentials::resolve())->toBeNull();
});
