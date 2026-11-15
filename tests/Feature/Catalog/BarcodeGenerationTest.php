<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\GenerateVariantBarcodes;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Ean13;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\ForkRace;

/*
 * In-store EAN-13 barcodes (owner request 2026-11-16): GS1 restricted-circulation prefix +
 * running number + check digit, from a locked counter row, skipping codes already in use and
 * never replacing a barcode a variant already has.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'catalog.barcode.prefix' => '20']);
});

/** A variable product with `$count` active variants and no barcodes. */
function barcodeProduct(int $count = 2): Product
{
    $product = Product::factory()->variable()->create();
    foreach (range(1, $count) as $i) {
        ProductVariant::factory()->for($product)->create(['position' => $i]);
    }

    return $product;
}

describe('EAN-13 arithmetic', function (): void {
    it('computes the check digit of known codes', function (string $code): void {
        expect(Ean13::checkDigit(substr($code, 0, 12)))->toBe((int) $code[12])
            ->and(Ean13::isValid($code))->toBeTrue();
    })->with(['2000000000015', '4006381333931', '8901234567890', '5901234123457', '0000000000000']);

    it('rejects a wrong check digit, a wrong length and non-digits', function (?string $code): void {
        expect(Ean13::isValid($code))->toBeFalse();
    })->with(['2000000000016', '8801234567890', '200000000001', '20000000000155', 'ABCDEFGHIJKLM', '', null]);

    it('builds prefix + zero-padded number + check digit', function (): void {
        expect(Ean13::build('20', 1))->toBe('2000000000015')
            ->and(Ean13::build('20', 2))->toBe('2000000000022')
            ->and(Ean13::build('299', 42))->toHaveLength(13)->toStartWith('299000000042')
            ->and(Ean13::isValid(Ean13::build('299', 42)))->toBeTrue()
            ->and(Ean13::capacity('20'))->toBe(9_999_999_999)
            ->and(fn () => Ean13::build('20', 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Ean13::build('20', 10_000_000_000))->toThrow(InvalidArgumentException::class);
    });
});

describe('GenerateVariantBarcodes', function (): void {
    it('numbers active variants without a barcode in id order from the counter', function (): void {
        $product = barcodeProduct(3);

        $made = app(GenerateVariantBarcodes::class)->handle([$product->id]);

        expect($made->pluck('barcode')->all())->toBe(['2000000000015', '2000000000022', '2000000000039'])
            ->and($product->variants()->orderBy('id')->pluck('barcode')->all())->toBe(['2000000000015', '2000000000022', '2000000000039'])
            ->and((int) DB::table('barcode_sequences')->where('prefix', '20')->value('next_number'))->toBe(4);
    });

    it('never overwrites a barcode and skips archived variants unless named', function (): void {
        $product = barcodeProduct(1);
        $typed = ProductVariant::factory()->for($product)->create(['barcode' => '8901234567890']);
        $archived = ProductVariant::factory()->for($product)->archived()->create();

        $made = app(GenerateVariantBarcodes::class)->handle([$product->id]);

        expect($made)->toHaveCount(1)
            ->and($typed->refresh()->barcode)->toBe('8901234567890')
            ->and($archived->refresh()->barcode)->toBeNull();

        $again = app(GenerateVariantBarcodes::class)->handle([$product->id], [$archived->id, $typed->id]);

        expect($again->modelKeys())->toBe([$archived->id])
            ->and($typed->refresh()->barcode)->toBe('8901234567890')
            ->and($archived->refresh()->barcode)->toBe('2000000000022');
    });

    it('skips a candidate that is already in use, without collisions', function (): void {
        // A shop typed the 2nd and 3rd in-store codes onto another product by hand.
        $other = Product::factory()->create();
        $other->variants()->first()?->update(['barcode' => '2000000000022']);
        ProductVariant::factory()->for($other)->create(['barcode' => '2000000000039']);
        $product = barcodeProduct(3);

        $made = app(GenerateVariantBarcodes::class)->handle([$product->id]);

        expect($made->pluck('barcode')->all())->toBe(['2000000000015', '2000000000046', '2000000000053'])
            ->and(ProductVariant::query()->whereNotNull('barcode')->count())->toBe(ProductVariant::query()->whereNotNull('barcode')->distinct()->count('barcode'));
    });

    it('carries on from the counter across calls and does not move it when nothing needs a code', function (): void {
        app(GenerateVariantBarcodes::class)->handle([barcodeProduct(2)->id]);
        $done = barcodeProduct(1);
        $done->variants()->first()?->update(['barcode' => 'MANUFACTURER-1']);

        expect(app(GenerateVariantBarcodes::class)->handle([$done->id]))->toBeEmpty()
            ->and((int) DB::table('barcode_sequences')->value('next_number'))->toBe(3)
            ->and(app(GenerateVariantBarcodes::class)->handle([barcodeProduct(1)->id])->first()?->barcode)->toBe('2000000000039');
    });

    it('uses the configured prefix and refuses a malformed one', function (): void {
        config(['catalog.barcode.prefix' => '299']);
        $made = app(GenerateVariantBarcodes::class)->handle([barcodeProduct(1)->id]);
        expect($made->first()?->barcode)->toBe(Ean13::build('299', 1));

        config(['catalog.barcode.prefix' => '2']);
        expect(fn () => app(GenerateVariantBarcodes::class)->handle([barcodeProduct(1)->id]))->toThrow(RuntimeException::class);
    });

    it('is a conflict when the prefix has no numbers left', function (): void {
        DB::table('barcode_sequences')->insert(['prefix' => '20', 'next_number' => Ean13::capacity('20'), 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => app(GenerateVariantBarcodes::class)->handle([barcodeProduct(2)->id]))->toThrow(CatalogConflict::class);
    });

    it('fires ProductChanged once per product that changed', function (): void {
        Event::fake([ProductChanged::class]);
        $a = barcodeProduct(2);
        $b = barcodeProduct(1);

        app(GenerateVariantBarcodes::class)->handle([$a->id, $b->id]);

        Event::assertDispatchedTimes(ProductChanged::class, 2);
    });
});

describe('endpoints', function (): void {
    beforeEach(function (): void {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    });

    it('POST /products/{product}/barcodes returns the variants that got one', function (): void {
        $product = barcodeProduct(2);
        $ids = $product->variants()->orderBy('id')->pluck('id')->all();

        $this->postJson("/api/v1/admin/products/{$product->id}/barcodes")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $ids[0])
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.barcode', '2000000000015')
            ->assertJsonPath('data.1.barcode', '2000000000022')
            ->assertJsonStructure(['data' => [['id', 'product_id', 'sku', 'barcode']]]);

        $this->postJson("/api/v1/admin/products/{$product->id}/barcodes")->assertOk()->assertJsonCount(0, 'data');
    });

    it('POST /products/{product}/barcodes takes some of its own variants only', function (): void {
        $product = barcodeProduct(2);
        $other = barcodeProduct(1);
        $first = $product->variants()->orderBy('id')->firstOrFail();

        $this->postJson("/api/v1/admin/products/{$product->id}/barcodes", ['variant_ids' => [$first->id]])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);

        $this->postJson("/api/v1/admin/products/{$product->id}/barcodes", ['variant_ids' => [$other->variants()->firstOrFail()->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.field', 'variant_ids.0');
    });

    it('POST /products/barcodes covers several products, capped at 200', function (): void {
        $a = barcodeProduct(2);
        $b = barcodeProduct(1);

        $this->postJson('/api/v1/admin/products/barcodes', ['product_ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonCount(3, 'data');

        $this->postJson('/api/v1/admin/products/barcodes', ['product_ids' => range(1, 201)])
            ->assertUnprocessable()->assertJsonPath('errors.0.field', 'product_ids');
        $this->postJson('/api/v1/admin/products/barcodes', ['product_ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/admin/products/barcodes', ['product_ids' => [999_999]])->assertUnprocessable();
    });
});

describe('on MySQL', function (): void {
    beforeEach(function (): void {
        if (onlyOnMysql()) {
            return;
        }
        ForkRace::open('barcode_race');
    });

    afterEach(function (): void {
        if (onlyOnMysql()) {
            return;
        }
        ForkRace::close();
    });

    it('never hands out the same code to two concurrent generators', function (): void {
        expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');
        $products = array_map(static fn (): int => barcodeProduct(3)->id, range(1, 8));

        $results = ForkRace::run(array_map(static fn (int $id): Closure => static fn (): string => app(GenerateVariantBarcodes::class)->handle([$id])->pluck('barcode')->implode(','), $products));

        expect(array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'error') || $r === 'no result')))->toBe([]);
        $codes = explode(',', implode(',', $results));
        expect($codes)->toHaveCount(24)
            ->and(array_unique($codes))->toHaveCount(24)
            ->and((int) DB::table('barcode_sequences')->value('next_number'))->toBe(25);
    });
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel generators need MySQL row locking (make test-mysql).');
