<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Actions\ApplyCourierStatus;
use App\Domain\Fulfilment\Actions\MarkShipmentsSubmitted;
use App\Domain\Fulfilment\Actions\RequestShipment;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Support\Money;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Support\CourierFixtures;

/*
 * LOAD-AND-CONCURRENCY F-5 (P9). ApplyCourierStatus completes a submitted booking inside a
 * savepoint and, on a refusal, records `completion_failed` and carries on. A deadlock is
 * not a refusal: InnoDB has rolled back the whole transaction, shipment lock included, so
 * "carrying on" wrote the failure outside any transaction. A deadlock must propagate (the
 * outer transaction retries; the poll/webhook job retries after that).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    CourierFixtures::install('steadfast');
});

function submittedShipment(): Shipment
{
    $variant = OrderFixtures::stockedVariant(5, '600.00', '350.00');
    $order = app(TransitionOrder::class)->handle(OrderFixtures::place([$variant->id => 1]), OrderStatus::Confirmed);
    $shipment = app(RequestShipment::class)->handle($order->id, dispatch: false);
    app(MarkShipmentsSubmitted::class)->handle([$shipment->id]);

    return $shipment->refresh();
}

it('lets a deadlock during booking completion propagate instead of recording completion_failed', function (): void {
    $shipment = submittedShipment();
    expect($shipment->status)->toBe(ShipmentStatus::Submitted);

    // The order update inside the completion savepoint loses a deadlock.
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'update "orders"')) {
            throw new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        }
    });

    expect(fn () => app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(
        ShipmentStatus::PickedUp, 'picked_up', charge: Money::of('80.00'), consignmentId: 'SF-1001', trackingCode: 'ab12cd34',
    ), 'webhook'))->toThrow(DeadlockException::class);

    expect($shipment->refresh()->failure_code)->not->toBe(Shipment::FAILURE_COMPLETION);
});

it('still records completion_failed for a real refusal (not a lock error)', function (): void {
    $shipment = submittedShipment();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'update "orders"')) {
            throw new RuntimeException('order refused');
        }
    });

    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(
        ShipmentStatus::PickedUp, 'picked_up', consignmentId: 'SF-1002',
    ), 'webhook');

    expect($shipment->refresh()->failure_code)->toBe(Shipment::FAILURE_COMPLETION)
        ->and($shipment->consignment_id)->toBe('SF-1002');
});
