<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Inventory\DTOs\StockTransferData;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockTransfer;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\InventoryOpsKit as Kit;

/*
 * P5-INV stock transfers: transfer_out + transfer_in in one transaction, total stock
 * constant, only what is available at the source, cost carried to the destination.
 */

const TRF_API = '/api/v1/admin/inventory/transfers';

function transferPost(array $body): Illuminate\Testing\TestResponse
{
    return test()->postJson(TRF_API, $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

function totalOnHand(): int
{
    return (int) StockLevel::query()->sum('on_hand');
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    Kit::user(Role::InventoryStaff);
    $this->main = StockLocation::defaultId();
    $this->ctg = Kit::branch()->id;
    $this->puzzle = Kit::variant('Wooden Alphabet Puzzle', '310.00');
    $this->doll = Kit::variant('Fashion Doll', '1480.00', 'ফ্যাশন পুতুল');
    Kit::stock($this->puzzle->id, 20);
    Kit::stock($this->doll->id, 6);
});

it('moves stock out of one location and into another, keeping the total constant', function (): void {
    $before = totalOnHand();

    $response = transferPost([
        'from_location_id' => $this->main,
        'to_location_id' => $this->ctg,
        'note' => 'Eid restock for Chattogram',
        'lines' => [['variant_id' => $this->doll->id, 'quantity' => 2], ['variant_id' => $this->puzzle->id, 'quantity' => 5]],
    ])->assertCreated()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('from_location_code', 'main')
        ->assertJsonPath('to_location_code', 'ctg');

    expect($response->json('number'))->toStartWith('TRF-')
        ->and(Kit::onHand($this->puzzle->id))->toBe(15)
        ->and(Kit::onHand($this->puzzle->id, $this->ctg))->toBe(5)
        ->and(Kit::onHand($this->doll->id))->toBe(4)
        ->and(Kit::onHand($this->doll->id, $this->ctg))->toBe(2)
        ->and(totalOnHand())->toBe($before);

    $movements = StockMovement::query()->where('reference_type', StockTransfer::REFERENCE_TYPE)->orderBy('id')->get();
    expect($movements)->toHaveCount(4)
        ->and($movements->pluck('type')->map->value->countBy()->all())->toBe(['transfer_out' => 2, 'transfer_in' => 2])
        ->and($movements->sum('quantity'))->toBe(0)
        ->and($movements->every(fn (StockMovement $m): bool => $m->unit_cost !== null))->toBeTrue();

    $this->getJson('/api/v1/admin/inventory/movements?filter[type]=transfer_in')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reference_number', $response->json('number'));
    $this->getJson(TRF_API)->assertOk()->assertJsonCount(1, 'data');
    Kit::assertInvariants();
});

it('only moves what is available at the source — reserved units stay', function (): void {
    app(ReserveStock::class)->handle([$this->puzzle->id => 16]); // 20 on hand, 4 available

    transferPost([
        'from_location_id' => $this->main,
        'to_location_id' => $this->ctg,
        'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => 5]],
    ])->assertUnprocessable()->assertJsonPath('errors.0.field', 'lines.0.quantity');

    expect(Kit::onHand($this->puzzle->id))->toBe(20)
        ->and(StockTransfer::query()->count())->toBe(0)
        ->and(StockMovement::query()->where('reference_type', StockTransfer::REFERENCE_TYPE)->count())->toBe(0);
    Kit::assertInvariants();
});

it('validates the request: two different active locations, positive distinct lines', function (array $body, string $field): void {
    transferPost(array_merge([
        'from_location_id' => $this->main,
        'to_location_id' => $this->ctg,
        'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => 1]],
    ], $body))->assertUnprocessable()->assertJsonFragment(['field' => $field]);
})->with([
    'same location' => [['to_location_id' => 1], 'to_location_id'],
    'zero quantity' => [['lines' => [['variant_id' => 1, 'quantity' => 0]]], 'lines.0.quantity'],
    'no lines' => [['lines' => []], 'lines'],
]);

it('blends the transferred cost into the destination weighted average', function (): void {
    StockLevel::query()->where('variant_id', $this->puzzle->id)->where('location_id', $this->main)->update(['avg_cost' => '300.0000']);
    Kit::stock($this->puzzle->id, 10, $this->ctg);
    StockLevel::query()->where('variant_id', $this->puzzle->id)->where('location_id', $this->ctg)->update(['avg_cost' => '330.0000']);

    app(TransferStock::class)->handle(new StockTransferData($this->main, $this->ctg, [$this->puzzle->id => 10]));

    // (10 × 330 + 10 × 300) / 20 = 315
    expect(StockLevel::query()->where('variant_id', $this->puzzle->id)->where('location_id', $this->ctg)->first()->avg_cost)->toEqual('315.0000')
        ->and(StockLevel::query()->where('variant_id', $this->puzzle->id)->where('location_id', $this->main)->first()->avg_cost)->toEqual('300.0000');
    Kit::assertInvariants();
});

it('transfers both ways in a row without losing a unit', function (): void {
    app(TransferStock::class)->handle(new StockTransferData($this->main, $this->ctg, [$this->puzzle->id => 7, $this->doll->id => 3]));
    app(TransferStock::class)->handle(new StockTransferData($this->ctg, $this->main, [$this->doll->id => 1, $this->puzzle->id => 2]));

    expect(Kit::onHand($this->puzzle->id))->toBe(15)
        ->and(Kit::onHand($this->puzzle->id, $this->ctg))->toBe(5)
        ->and(Kit::onHand($this->doll->id))->toBe(4)
        ->and(totalOnHand())->toBe(26);
    Kit::assertInvariants();
});
