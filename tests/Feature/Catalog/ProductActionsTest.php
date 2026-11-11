<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\ProductWriteContext;
use App\Domain\Catalog\DTOs\RelationData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\DTOs\VariantDefaultsData;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CatalogFixtures;

/*
 * P4-CAT-03 — CreateProduct / UpdateProduct / ArchiveProduct and the variant matrix.
 */

/** Rebuild a ProductData with some arguments changed. @param  array<string, mixed>  $changes */
function productDataWith(ProductData $data, array $changes): ProductData
{
    return new ProductData(...[...get_object_vars($data), ...$changes]);
}

/** @return array<string, list<string>> */
function productErrors(Closure $write): array
{
    try {
        $write();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

it('gives a simple product exactly one default variant', function (): void {
    $product = app(CreateProduct::class)->handle(CatalogFixtures::simple());

    $variants = $product->variants()->get();

    expect($variants)->toHaveCount(1)
        ->and($variants[0]->is_default)->toBeTrue()
        ->and($variants[0]->status)->toBe(VariantStatus::Active)
        ->and($variants[0]->price->toDecimal())->toBe('850.00')
        ->and($variants[0]->cost_price->toDecimal())->toBe('520.00')
        ->and($variants[0]->sku)->toBe(sprintf('TM-%05d', $product->id))
        ->and($variants[0]->attributeValues)->toHaveCount(0);
});

it('creates a simple product from variant_defaults when no row is given', function (): void {
    $data = productDataWith(CatalogFixtures::simple(), ['variants' => [], 'variantDefaults' => new VariantDefaultsData(price: Money::of('99.5'))]);

    $product = app(CreateProduct::class)->handle($data);

    expect($product->variants()->sole()->price->toDecimal())->toBe('99.50');
});

it('builds a 3×4 matrix into 12 variants with attribute values and generated SKUs', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();

    $product = app(CreateProduct::class)->handle(CatalogFixtures::variable($colors, $sizes));
    $variants = $product->variants()->with('attributeValues')->get();

    expect($variants)->toHaveCount(12)
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($variants[0]->is_default)->toBeTrue()
        ->and(DB::table('variant_attribute_values')->count())->toBe(24)
        ->and($variants->pluck('sku')->unique())->toHaveCount(12)
        ->and($variants[0]->sku)->toBe(sprintf('TM-%05d-RED-S', $product->id))
        ->and($variants[11]->sku)->toBe(sprintf('TM-%05d-GREEN-XL', $product->id))
        ->and($variants[0]->name)->toBe('Red / Small')
        ->and($variants[0]->name_bn)->toBe('লাল / ছোট')
        ->and($variants->pluck('position')->all())->toBe(range(0, 11))
        ->and($variants->every(fn (ProductVariant $v): bool => $v->price->toDecimal() === '1450.00'))->toBeTrue();

    foreach ($variants as $variant) {
        expect($variant->attributeValues)->toHaveCount(2)
            ->and($variant->attributeValues->pluck('pivot.attribute_id')->sort()->values()->all())
            ->toBe(collect([$colors[0]->attribute_id, $sizes[0]->attribute_id])->sort()->values()->all());
    }
});

it('applies per-row overrides: SKU, barcode, price, compare-at, weight, status', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();

    $data = CatalogFixtures::variable($colors, [$sizes[0], $sizes[1]], [
        'variants' => [new VariantData(
            attributeValueIds: [$sizes[1]->id, $colors[2]->id],
            sku: 'RAIN-GRN-M', barcode: '8901234567890', price: Money::of('1600'), compareAtPrice: Money::of('1800'),
            weightGrams: 420, status: VariantStatus::Archived,
        )],
    ]);

    $product = app(CreateProduct::class)->handle($data);
    $row = ProductVariant::query()->where('sku', 'RAIN-GRN-M')->sole();

    expect($product->variants()->count())->toBe(6)
        ->and($row->barcode)->toBe('8901234567890')
        ->and($row->price->toDecimal())->toBe('1600.00')
        ->and($row->compare_at_price?->toDecimal())->toBe('1800.00')
        ->and($row->weight_grams)->toBe(420)
        ->and($row->status)->toBe(VariantStatus::Archived)
        ->and($row->name)->toBe('Green / Medium');
});

it('archives, never deletes, the variants of a removed value and keeps the rest untouched', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable($colors, $sizes);
    $product = app(CreateProduct::class)->handle($data);
    $before = $product->variants()->with('attributeValues')->get()->keyBy('id');

    // Drop Green.
    app(UpdateProduct::class)->handle($product, CatalogFixtures::variable([$colors[0], $colors[1]], $sizes, ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]));

    $after = $product->variants()->with('attributeValues')->get();

    expect($after)->toHaveCount(12)
        ->and($after->where('status', VariantStatus::Active))->toHaveCount(8)
        ->and($after->where('status', VariantStatus::Archived))->toHaveCount(4);

    foreach ($after as $variant) {
        $wasGreen = $variant->attributeValues->contains('id', $colors[2]->id);
        expect($variant->status)->toBe($wasGreen ? VariantStatus::Archived : VariantStatus::Active)
            ->and($variant->sku)->toBe($before[$variant->id]->sku)
            ->and($variant->price->toDecimal())->toBe($before[$variant->id]->price->toDecimal());
    }
});

it('adds only the new combinations when a value is added, keeping ids, SKUs and prices', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable([$colors[0], $colors[1]], $sizes);
    $product = app(CreateProduct::class)->handle($data);
    $firstId = $product->variants()->value('id');

    // Reprice one existing variant, then add Green with a different default price.
    $red = $colors[0];
    app(UpdateProduct::class)->handle($product, productDataWith($data, [
        'variants' => [new VariantData(attributeValueIds: [$red->id, $sizes[0]->id], price: Money::of('1500'))],
    ]));

    $added = productDataWith(CatalogFixtures::variable($colors, $sizes, ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]), [
        'variantDefaults' => new VariantDefaultsData(price: Money::of('1999')),
    ]);
    app(UpdateProduct::class)->handle($product, $added);

    $variants = $product->variants()->get();

    expect($variants)->toHaveCount(12)
        ->and($variants->where('status', VariantStatus::Active))->toHaveCount(12)
        ->and($variants->firstWhere('id', $firstId)?->price->toDecimal())->toBe('1500.00')
        ->and($variants->firstWhere('id', $firstId)?->is_default)->toBeTrue()
        ->and($variants->filter(fn (ProductVariant $v): bool => $v->price->toDecimal() === '1999.00'))->toHaveCount(4)
        ->and($variants->filter(fn (ProductVariant $v): bool => $v->price->toDecimal() === '1450.00'))->toHaveCount(7);
});

it('reactivates archived variants when a removed value comes back, without duplicating them', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable($colors, [$sizes[0]]);
    $product = app(CreateProduct::class)->handle($data);
    $greenSku = $product->variants()->where('sku', 'like', '%GREEN%')->value('sku');

    app(UpdateProduct::class)->handle($product, CatalogFixtures::variable([$colors[0], $colors[1]], [$sizes[0]], ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]));
    expect(ProductVariant::query()->where('sku', $greenSku)->value('status'))->toBe(VariantStatus::Archived);

    app(UpdateProduct::class)->handle($product, $data);

    expect($product->variants()->count())->toBe(3)
        ->and(ProductVariant::query()->where('sku', $greenSku)->value('status'))->toBe(VariantStatus::Active);
});

it('moves the default to the first active variant when the default is archived', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable($colors, [$sizes[0]]);
    $product = app(CreateProduct::class)->handle($data);

    app(UpdateProduct::class)->handle($product, CatalogFixtures::variable([$colors[1], $colors[2]], [$sizes[0]], ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]));

    $default = $product->variants()->where('is_default', true)->sole();
    expect($default->name)->toBe('Blue / Small')
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

it('archives the whole old matrix when the attribute set changes', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable($colors, []);
    $product = app(CreateProduct::class)->handle($data);

    app(UpdateProduct::class)->handle($product, CatalogFixtures::variable($colors, [$sizes[0], $sizes[1]], ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]));

    expect($product->variants()->count())->toBe(9)
        ->and($product->variants()->where('status', 'archived')->count())->toBe(3)
        ->and($product->variants()->where('status', 'active')->count())->toBe(6);
});

it('generates an ASCII slug from a Bangla-only name and makes it unique', function (): void {
    $first = app(CreateProduct::class)->handle(CatalogFixtures::simple(['name' => 'খেলনা গাড়ি', 'nameBn' => null]));
    $second = app(CreateProduct::class)->handle(CatalogFixtures::simple(['name' => 'খেলনা গাড়ি', 'nameBn' => null]));

    expect($first->slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
        ->and($second->slug)->toBe($first->slug.'-2');
});

it('keeps the slug on rename and changes it only when given', function (): void {
    $data = CatalogFixtures::simple(['name' => 'Toy Car']);
    $product = app(CreateProduct::class)->handle($data);

    app(UpdateProduct::class)->handle($product, productDataWith($data, ['name' => 'Toy Racing Car']));
    expect($product->refresh()->slug)->toBe('toy-car');

    app(UpdateProduct::class)->handle($product, productDataWith($data, ['slug' => 'racing-car']));
    expect($product->refresh()->slug)->toBe('racing-car');
});

it('attaches categories and requires the primary category to be one of them', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    $product = app(CreateProduct::class)->handle(CatalogFixtures::simple(['primaryCategoryId' => $a->id, 'categoryIds' => [$a->id, $b->id]]));
    expect($product->categories()->pluck('categories.id')->sort()->values()->all())->toBe([$a->id, $b->id]);

    $errors = productErrors(fn () => app(CreateProduct::class)->handle(CatalogFixtures::simple(['primaryCategoryId' => $a->id, 'categoryIds' => [$b->id]])));
    expect($errors)->toHaveKey('primary_category_id');
});

it('rejects invalid money and matrix definitions with field-keyed errors', function (Closure $build, string $field): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();

    $errors = productErrors(fn () => app(CreateProduct::class)->handle($build($colors, $sizes)));

    expect($errors)->toHaveKey($field);
})->with([
    'compare-at equal to price' => [fn () => CatalogFixtures::simple(['variants' => [new VariantData(price: Money::of('500'), compareAtPrice: Money::of('500'))]]), 'variants.0.compare_at_price'],
    'compare-at below price' => [fn () => CatalogFixtures::simple(['variants' => [new VariantData(price: Money::of('500'), compareAtPrice: Money::of('450'))]]), 'variants.0.compare_at_price'],
    'negative price' => [fn () => CatalogFixtures::simple(['variants' => [new VariantData(price: Money::of('-1'))]]), 'variants.0.price'],
    'negative cost' => [fn () => CatalogFixtures::simple(['variants' => [new VariantData(price: Money::of('10'), costPrice: Money::of('-5'))]]), 'variants.0.cost_price'],
    'no price at all' => [fn () => CatalogFixtures::simple(['variants' => [new VariantData]]), 'variants.0.price'],
    'two rows on a simple product' => [fn () => CatalogFixtures::simple(['variants' => [new VariantData(price: Money::of('1')), new VariantData(price: Money::of('2'))]]), 'variants'],
    'options on a simple product' => [fn ($c, $s) => productDataWith(CatalogFixtures::variable($c, $s), ['type' => ProductType::Simple, 'variants' => []]), 'options'],
    'variable without options' => [fn ($c, $s) => CatalogFixtures::variable([], []), 'options'],
    'default compare-at below price' => [fn ($c, $s) => productDataWith(CatalogFixtures::variable($c, $s), ['variantDefaults' => new VariantDefaultsData(price: Money::of('100'), compareAtPrice: Money::of('90'))]), 'variant_defaults.compare_at_price'],
    'row with a value not selected' => [fn ($c, $s) => CatalogFixtures::variable([$c[0]], $s, ['variants' => [new VariantData(attributeValueIds: [$c[1]->id, $s[0]->id])]]), 'variants.0.attribute_value_ids'],
    'row missing an attribute' => [fn ($c, $s) => CatalogFixtures::variable($c, $s, ['variants' => [new VariantData(attributeValueIds: [$c[0]->id])]]), 'variants.0.attribute_value_ids'],
    'age range inverted' => [fn () => CatalogFixtures::simple(['ageMinMonths' => 48, 'ageMaxMonths' => 24]), 'age_max_months'],
    'unknown category' => [fn () => CatalogFixtures::simple(['primaryCategoryId' => 999999, 'categoryIds' => [999999]]), 'category_ids'],
]);

it('rejects a non-variant-defining attribute as an option', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    ProductAttribute::query()->whereKey($sizes[0]->attribute_id)->update(['is_variant_defining' => false]);

    expect(productErrors(fn () => app(CreateProduct::class)->handle(CatalogFixtures::variable($colors, $sizes))))->toHaveKey('options.1.attribute_id');
});

it('enforces unique SKU and barcode across products', function (): void {
    app(CreateProduct::class)->handle(CatalogFixtures::simple(['variants' => [new VariantData(sku: 'DUP-1', barcode: '1234567890123', price: Money::of('100'))]]));

    $sku = productErrors(fn () => app(CreateProduct::class)->handle(CatalogFixtures::simple(['variants' => [new VariantData(sku: 'DUP-1', price: Money::of('100'))]])));
    $barcode = productErrors(fn () => app(CreateProduct::class)->handle(CatalogFixtures::simple(['variants' => [new VariantData(barcode: '1234567890123', price: Money::of('100'))]])));

    expect($sku)->toHaveKey('variants.0.sku')
        ->and($barcode)->toHaveKey('variants.0.barcode')
        ->and(Product::query()->count())->toBe(1);
});

it('enforces unique SKU and barcode at the database too', function (string $column): void {
    $variant = ProductVariant::factory()->create(['barcode' => '5550001112223']);

    expect(fn () => ProductVariant::factory()->create([$column => $variant->getAttribute($column)]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
})->with(['sku', 'barcode']);

it('allows many variants without a barcode', function (): void {
    ProductVariant::factory()->count(3)->create(['barcode' => null]);

    expect(ProductVariant::query()->whereNull('barcode')->count())->toBe(3);
});

it('rejects an upsell that is not more expensive, and accepts one that is', function (): void {
    $cheap = Product::factory()->priced('500.00')->create();
    $same = Product::factory()->priced('850.00')->create();
    $dearer = Product::factory()->priced('1200.00')->create();

    foreach ([$cheap, $same] as $target) {
        $errors = productErrors(fn () => app(CreateProduct::class)->handle(CatalogFixtures::simple([
            'relations' => [new RelationData($target->id, RelationType::Upsell)],
        ])));
        expect($errors)->toHaveKey('relations.0.related_product_id');
    }

    $product = app(CreateProduct::class)->handle(CatalogFixtures::simple([
        'relations' => [
            new RelationData($dearer->id, RelationType::Upsell, reasonNote: 'Bigger set'),
            new RelationData($cheap->id, RelationType::CrossSell, position: 1),
            new RelationData($cheap->id, RelationType::AgeProgression),
        ],
    ]));

    expect($product->productRelations()->count())->toBe(3)
        ->and($product->productRelations()->where('type', 'upsell')->value('reason_note'))->toBe('Bigger set');
});

it('refuses a self-relation and an unknown target', function (): void {
    $data = CatalogFixtures::simple();
    $product = app(CreateProduct::class)->handle($data);

    $self = productErrors(fn () => app(UpdateProduct::class)->handle($product, productDataWith($data, ['relations' => [new RelationData($product->id, RelationType::Related)]])));
    $missing = productErrors(fn () => app(UpdateProduct::class)->handle($product, productDataWith($data, ['relations' => [new RelationData(999999, RelationType::Related)]])));

    expect($self)->toHaveKey('relations.0.related_product_id')
        ->and($missing)->toHaveKey('relations.0.related_product_id');
});

it('replaces curated relations but never touches rule-generated ones', function (): void {
    $data = CatalogFixtures::simple();
    $product = app(CreateProduct::class)->handle($data);
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $b->id, 'type' => RelationType::CrossSell, 'is_auto' => true]);

    app(UpdateProduct::class)->handle($product, productDataWith($data, ['relations' => [new RelationData($a->id, RelationType::Related)]]));
    app(UpdateProduct::class)->handle($product, productDataWith($data, ['relations' => []]));

    expect($product->productRelations()->get()->map(fn (ProductRelation $r): array => [$r->related_product_id, $r->is_auto])->all())
        ->toBe([[$b->id, true]]);

    // relations: null leaves curated rows alone.
    app(UpdateProduct::class)->handle($product, productDataWith($data, ['relations' => [new RelationData($a->id, RelationType::Related)]]));
    app(UpdateProduct::class)->handle($product, productDataWith($data, ['name' => 'Renamed', 'relations' => null]));
    expect($product->productRelations()->count())->toBe(2);
});

it('refuses to change the product type', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::simple();
    $product = app(CreateProduct::class)->handle($data);

    $variable = CatalogFixtures::variable($colors, $sizes, ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]);
    expect(productErrors(fn () => app(UpdateProduct::class)->handle($product, $variable)))->toHaveKey('type');
});

it('needs at least one active variant', function (): void {
    $data = CatalogFixtures::simple();
    $product = app(CreateProduct::class)->handle($data);

    $errors = productErrors(fn () => app(UpdateProduct::class)->handle($product, productDataWith($data, ['variants' => [new VariantData(status: VariantStatus::Archived)]])));

    expect($errors)->toHaveKey('variants')
        ->and($product->variants()->sole()->status)->toBe(VariantStatus::Active);
});

it('stamps published_at on first activation and keeps it afterwards', function (): void {
    $data = CatalogFixtures::simple(['status' => ProductStatus::Draft]);
    $product = app(CreateProduct::class)->handle($data);
    expect($product->published_at)->toBeNull();

    app(UpdateProduct::class)->handle($product, productDataWith($data, ['status' => ProductStatus::Active]));
    $published = $product->refresh()->published_at;
    expect($published)->not->toBeNull();

    $this->travel(1)->days();
    app(UpdateProduct::class)->handle($product, productDataWith($data, ['status' => ProductStatus::Active, 'name' => 'New name']));
    expect($product->refresh()->published_at?->equalTo($published))->toBeTrue();
});

it('audits price and cost changes on existing variants, not unchanged saves', function (): void {
    $data = CatalogFixtures::simple();
    $product = app(CreateProduct::class)->handle($data);
    $user = App\Models\User::factory()->create();

    app(UpdateProduct::class)->handle($product, $data, new ProductWriteContext(actorId: $user->id));
    expect(AuditSensitive::query()->count())->toBe(0);

    app(UpdateProduct::class)->handle($product, productDataWith($data, [
        'variants' => [new VariantData(price: Money::of('900'), costPrice: Money::of('550'))],
    ]), new ProductWriteContext(actorId: $user->id));

    $price = AuditSensitive::query()->where('action', AuditAction::PriceChanged->value)->sole();
    $cost = AuditSensitive::query()->where('action', AuditAction::CostChanged->value)->sole();

    expect($price->user_id)->toBe($user->id)
        ->and($price->before)->toBe(['price' => '850.00', 'compare_at_price' => null])
        ->and($price->after)->toBe(['price' => '900.00', 'compare_at_price' => null])
        ->and($cost->before)->toBe(['cost_price' => '520.00'])
        ->and($cost->after)->toBe(['cost_price' => '550.00']);
});

it('refuses price changes and new variants without the price ability, but allows unchanged prices', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable([$colors[0]], [$sizes[0]]);
    $product = app(CreateProduct::class)->handle($data);
    $noPrice = new ProductWriteContext(canChangePrices: false, canSetCost: false);
    $copyOnly = productDataWith($data, ['name' => 'Better name', 'variantDefaults' => null]);

    app(UpdateProduct::class)->handle($product, $copyOnly, $noPrice);
    expect($product->refresh()->name)->toBe('Better name');

    $reprice = productDataWith($copyOnly, ['variants' => [new VariantData(attributeValueIds: [$colors[0]->id, $sizes[0]->id], price: Money::of('1'))]]);
    expect(fn () => app(UpdateProduct::class)->handle($product, $reprice, $noPrice))->toThrow(AuthorizationException::class);

    $grow = productDataWith(CatalogFixtures::variable($colors, [$sizes[0]], ['primaryCategoryId' => $data->primaryCategoryId, 'categoryIds' => $data->categoryIds]), ['variantDefaults' => null]);
    expect(fn () => app(UpdateProduct::class)->handle($product, $grow, $noPrice))->toThrow(AuthorizationException::class);

    $cost = productDataWith($copyOnly, ['variants' => [new VariantData(attributeValueIds: [$colors[0]->id, $sizes[0]->id], costPrice: Money::of('1'))]]);
    expect(fn () => app(UpdateProduct::class)->handle($product, $cost, $noPrice))->toThrow(AuthorizationException::class)
        ->and($product->variants()->count())->toBe(1)
        ->and($product->variants()->sole()->price->toDecimal())->toBe('1450.00');
});

it('archives idempotently and keeps the variants', function (): void {
    $product = app(CreateProduct::class)->handle(CatalogFixtures::simple());

    app(ArchiveProduct::class)->handle($product);
    app(ArchiveProduct::class)->handle($product);

    expect($product->refresh()->status)->toBe(ProductStatus::Archived)
        ->and($product->variants()->sole()->status)->toBe(VariantStatus::Active);
});

it('rolls back the whole write when a late rule fails', function (): void {
    $cheap = Product::factory()->priced('100.00')->create();

    productErrors(fn () => app(CreateProduct::class)->handle(CatalogFixtures::simple([
        'relations' => [new RelationData($cheap->id, RelationType::Upsell)],
    ])));

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1);
});

it('fires ProductChanged after commit with the old slug on rename, and not for a no-op update', function (): void {
    Event::fake([ProductChanged::class]);
    $data = CatalogFixtures::simple(['name' => 'Puzzle Box']);

    $product = app(CreateProduct::class)->handle($data);
    Event::assertDispatched(ProductChanged::class, fn (ProductChanged $e): bool => $e->slug === 'puzzle-box' && $e->previousSlug === null);

    app(UpdateProduct::class)->handle($product, $data);
    Event::assertDispatchedTimes(ProductChanged::class, 1);

    app(UpdateProduct::class)->handle($product, productDataWith($data, ['slug' => 'puzzle-box-deluxe']));
    Event::assertDispatched(ProductChanged::class, fn (ProductChanged $e): bool => $e->slug === 'puzzle-box-deluxe' && $e->previousSlug === 'puzzle-box');

    app(ArchiveProduct::class)->handle($product);
    Event::assertDispatchedTimes(ProductChanged::class, 3);
});

it('duplicates a product as a draft with a new slug, new SKUs and no barcodes', function (): void {
    ['colors' => $colors, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $data = CatalogFixtures::variable($colors, [$sizes[0]], [
        'variants' => [new VariantData(attributeValueIds: [$colors[0]->id, $sizes[0]->id], barcode: '8800000000017', price: Money::of('1600'))],
    ]);
    $source = app(CreateProduct::class)->handle($data);
    $target = Product::factory()->create();
    ProductRelation::factory()->create(['product_id' => $source->id, 'related_product_id' => $target->id]);
    App\Domain\Catalog\Models\ProductImage::factory()->create(['product_id' => $source->id, 'variant_id' => $source->variants()->value('id')]);

    $copy = app(App\Domain\Catalog\Actions\DuplicateProduct::class)->handle($source);
    $copyVariants = $copy->variants()->with('attributeValues')->get();

    expect($copy->status)->toBe(ProductStatus::Draft)
        ->and($copy->name)->toBe('Kids Raincoat (copy)')
        ->and($copy->slug)->not->toBe($source->slug)
        ->and($copy->categories()->pluck('categories.id')->all())->toBe($source->categories()->pluck('categories.id')->all())
        ->and($copyVariants)->toHaveCount(3)
        ->and($copyVariants->pluck('sku')->intersect($source->variants()->pluck('sku')))->toHaveCount(0)
        ->and($copyVariants[0]->sku)->toBe(sprintf('TM-%05d-RED-S', $copy->id))
        ->and($copyVariants[0]->price->toDecimal())->toBe('1600.00')
        ->and($copyVariants[0]->barcode)->toBeNull()
        ->and($copyVariants[0]->attributeValues)->toHaveCount(2)
        ->and($copyVariants->where('is_default', true))->toHaveCount(1)
        ->and($copy->images()->sole()->variant_id)->toBe($copyVariants[0]->id)
        ->and($copy->productRelations()->sole()->related_product_id)->toBe($target->id);
});
