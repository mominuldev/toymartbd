<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\SetReorderLevels;
use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockAdjustmentLine;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\InventoryDigest;
use App\Domain\Notification\Mail\InventoryDigestMail;
use App\Models\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Inventory\InventoryOpsKit as Kit;

/*
 * Low / out-of-stock alerts against reorder points, raised as stock moves, and the
 * managers' daily digest — bucketed by the Dhaka date an alert was raised (rule 11).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    Mail::fake();

    $this->manager = Kit::user(Role::Manager, actAs: false);
    $this->main = StockLocation::defaultId();
    $this->puzzle = Kit::variant('Wooden Alphabet Puzzle', '310.00');
    Kit::stock($this->puzzle->id, 20);
    app(SetReorderLevels::class)->handle($this->main, [$this->puzzle->id => ['point' => 8, 'quantity' => 24]]);
});

function digestRemove(int $variantId, int $units): void
{
    app(AdjustStock::class)->handle(new StockAdjustmentData(StockAdjustmentReason::Damage, [new StockAdjustmentLine($variantId, -$units)]));
}

it('opens a low-stock alert when available falls to the reorder point, then out of stock, then resolves', function (): void {
    digestRemove($this->puzzle->id, 12); // 8 left = reorder point
    $low = InventoryAlert::query()->open()->sole();
    expect($low->kind)->toBe(InventoryAlertKind::LowStock)->and($low->actual)->toBe(8)->and($low->expected)->toBe(8);

    digestRemove($this->puzzle->id, 8);
    expect(InventoryAlert::query()->open()->sole()->kind)->toBe(InventoryAlertKind::OutOfStock)
        ->and($low->refresh()->status)->toBe(InventoryAlertStatus::Resolved);

    app(AdjustStock::class)->handle(new StockAdjustmentData(StockAdjustmentReason::Found, [new StockAdjustmentLine($this->puzzle->id, 30)]), canApprove: true);
    expect(InventoryAlert::query()->open()->count())->toBe(0);
    Kit::assertInvariants();
});

it('keeps one open alert per variant and location while the level stays low', function (): void {
    digestRemove($this->puzzle->id, 13);
    digestRemove($this->puzzle->id, 1);

    expect(InventoryAlert::query()->where('kind', 'low_stock')->count())->toBe(1)
        ->and(InventoryAlert::query()->open()->sole()->actual)->toBe(6);
});

it('stamps the Dhaka date an alert was raised and the digest buckets by it', function (): void {
    // 19:30 UTC on the 12th is 01:30 on the 13th in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-11-12 19:30:00', 'UTC'));
    digestRemove($this->puzzle->id, 13);
    $this->travelBack();

    $alert = InventoryAlert::query()->open()->sole();
    expect($alert->alert_date->toDateString())->toBe('2026-11-13');

    $onTwelfth = app(InventoryDigest::class)->compile('2026-11-12');
    $onThirteenth = app(InventoryDigest::class)->compile('2026-11-13');

    expect($onTwelfth['raised']['low_stock'])->toBe(0)
        ->and($onThirteenth['raised']['low_stock'])->toBe(1)
        ->and($onThirteenth['open']['low_stock'])->toBe(1)
        ->and($onThirteenth['rows'][0])->toMatchArray(['sku' => $this->puzzle->sku, 'available' => 7, 'reorder_point' => 8, 'raised_on_date' => true])
        ->and($onTwelfth['rows'][0]['raised_on_date'])->toBeFalse();
});

it('mails the digest to managers for the previous Dhaka day at 09:00, and nothing on a quiet day', function (): void {
    $this->artisan('toymart:inventory:digest', ['--date' => '2026-11-12'])->assertSuccessful();
    Mail::assertNothingSent();

    digestRemove($this->puzzle->id, 13);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00', 'UTC')); // 09:00 on the 14th in Dhaka
    $this->artisan('toymart:inventory:digest')->assertSuccessful();

    Mail::assertSent(InventoryDigestMail::class, fn (InventoryDigestMail $mail): bool => $mail->hasTo($this->manager->email)
        && $mail->digest['date'] === '2026-11-13'
        && $mail->digest['open']['low_stock'] === 1);
});

it('renders the digest mail in English and Bangla', function (string $locale, string $needle): void {
    digestRemove($this->puzzle->id, 13);
    $html = (new InventoryDigestMail(app(InventoryDigest::class)->compile('2026-11-13'), $locale))->render();

    expect($html)->toContain($needle)->toContain($this->puzzle->sku);
})->with([['en', 'Low stock'], ['bn', 'স্টক কম']]);

it('ignores archived variants', function (): void {
    $this->puzzle->product->update(['status' => App\Domain\Catalog\Enums\ProductStatus::Archived]);
    digestRemove($this->puzzle->id, 20);

    expect(InventoryAlert::query()->open()->count())->toBe(0);
});
