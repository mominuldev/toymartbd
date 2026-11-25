<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Recommendations\RecommendationResolver;
use App\Domain\Catalog\Services\ProductDetail;
use App\Domain\Marketing\Services\FlashSaleLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Storefront\Recommendations\RecoKit;

/*
 * P10 recommendations on GET /api/v1/storefront/products/{slug} (PRD §3.1a, rules 25b–25c,
 * docs/api/recommendations.md): every guardrail, every cap, the block priority and the
 * frequently-bought-together block.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->storefront = CartTestKit::storefrontToken();
});

function recoPdp(Product $product): TestResponse
{
    return test()->withToken(test()->storefront)->getJson('/api/v1/storefront/products/'.$product->slug)->assertOk();
}

/** @return list<int> */
function recoIds(TestResponse $response, string $block): array
{
    return array_map(static fn (array $card): int => $card['id'], $response->json('recommendations.'.$block) ?? []);
}

it('shows an upsell with the server-computed price delta', function (): void {
    $source = RecoKit::product('1000.00');
    $better = RecoKit::product('1450.00');
    RecoKit::relate($source, $better, RelationType::Upsell);

    $response = recoPdp($source);

    expect($response->json('recommendations.upsell.0.id'))->toBe($better->id)
        ->and($response->json('recommendations.upsell.0.price'))->toBe('1450.00')
        ->and($response->json('recommendations.upsell.0.price_delta'))->toBe('450.00')
        ->and($response->json('recommendations.upsell.0.in_stock'))->toBeTrue()
        ->and($response->json('recommendations.upsell.0.name_bn'))->toStartWith('খেলনা')
        ->and($response->json('recommendations.fbt'))->toBeNull();
});

it('drops an upsell that is not dearer on read', function (): void {
    $source = RecoKit::product('1000.00');
    $cheaper = RecoKit::product('900.00');
    $same = RecoKit::product('1000.00');
    RecoKit::relate($source, $cheaper, RelationType::Upsell, 1);
    RecoKit::relate($source, $same, RelationType::Upsell, 2);

    expect(recoIds(recoPdp($source), 'upsell'))->toBe([]);
});

it('drops out-of-stock, archived, unpublished, scheduled, deleted, offline and self targets', function (): void {
    $source = RecoKit::product('1000.00');
    $ok = RecoKit::product('500.00');
    $targets = [
        'sold out' => RecoKit::product('500.00', 0),
        'archived' => RecoKit::product('500.00', 5, ['status' => ProductStatus::Archived]),
        'draft' => RecoKit::product('500.00', 5, ['status' => ProductStatus::Draft]),
        'scheduled' => RecoKit::product('500.00', 5, ['published_at' => now()->addDay()]),
        'not in the online store' => RecoKit::product('500.00', 5, ['channel_online_store' => false]),
        'deleted' => RecoKit::product('500.00'),
    ];
    $targets['deleted']->delete();
    $reserved = RecoKit::product('500.00', 2);
    DB::table('stock_levels')->where('variant_id', $reserved->variants()->value('id'))->update(['reserved' => 2]);
    $targets['all reserved'] = $reserved;

    RecoKit::relate($source, $source, RelationType::CrossSell, 0);
    foreach (array_values($targets) as $i => $target) {
        RecoKit::relate($source, $target, RelationType::CrossSell, $i + 1);
    }
    RecoKit::relate($source, $ok, RelationType::CrossSell, 99);

    expect(recoIds(recoPdp($source), 'cross_sell'))->toBe([$ok->id]);
});

it('caps each block: 2 upsells, 4 cross-sells, 4 next-stage', function (): void {
    $source = RecoKit::product('1000.00', 10, ['age_min_months' => 12, 'age_max_months' => 24]);
    $upsells = $crossSells = $stages = [];
    for ($i = 0; $i < 4; $i++) {
        RecoKit::relate($source, $upsells[] = RecoKit::product((string) (2000 + $i).'.00'), RelationType::Upsell, $i);
    }
    for ($i = 0; $i < 6; $i++) {
        RecoKit::relate($source, $crossSells[] = RecoKit::product('300.00'), RelationType::CrossSell, $i);
        RecoKit::relate($source, $stages[] = RecoKit::product('1200.00'), RelationType::AgeProgression, $i);
    }

    $response = recoPdp($source);

    expect(recoIds($response, 'upsell'))->toBe([$upsells[0]->id, $upsells[1]->id])
        ->and(recoIds($response, 'cross_sell'))->toBe(array_map(fn (Product $p): int => $p->id, array_slice($crossSells, 0, 4)))
        ->and(recoIds($response, 'age_progression'))->toBe(array_map(fn (Product $p): int => $p->id, array_slice($stages, 0, 4)));
});

it('honours configured caps', function (): void {
    config(['catalog.recommendations.cross_sell' => 1]);
    $source = RecoKit::product();
    RecoKit::relate($source, $a = RecoKit::product(), RelationType::CrossSell, 0);
    RecoKit::relate($source, RecoKit::product(), RelationType::CrossSell, 1);

    expect(recoIds(recoPdp($source), 'cross_sell'))->toBe([$a->id]);
});

it('shows a product in one block only: upsell > fbt > cross_sell > age_progression; related excludes them all', function (): void {
    $category = RecoKit::category();
    $source = RecoKit::product('1000.00', 10, ['primary_category_id' => $category->id, 'age_min_months' => 12, 'age_max_months' => 24]);
    $both = RecoKit::product('1500.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 24, 'age_max_months' => 48]);
    $bought = RecoKit::product('400.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 24, 'age_max_months' => 48]);
    $plain = RecoKit::product('300.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 0, 'age_max_months' => 12]);

    RecoKit::relate($source, $both, RelationType::Upsell);
    RecoKit::relate($source, $both, RelationType::CrossSell);
    RecoKit::relate($source, $both, RelationType::AgeProgression);
    RecoKit::relate($source, $bought, RelationType::CrossSell, 1);
    RecoKit::relate($source, $plain, RelationType::CrossSell, 2);
    RecoKit::relate($source, $plain, RelationType::Related);
    RecoKit::relate($source, $bought, RelationType::Related);
    RecoKit::copurchase($source, $bought, 5);

    $response = recoPdp($source);

    expect(recoIds($response, 'upsell'))->toBe([$both->id])
        ->and(array_column($response->json('recommendations.fbt.items'), 'id'))->toBe([$source->id, $bought->id])
        ->and(recoIds($response, 'cross_sell'))->toBe([$plain->id])
        // $both and $bought are next-stage by age and category, but already shown higher up.
        ->and(recoIds($response, 'age_progression'))->toBe([])
        ->and(array_column($response->json('related'), 'id'))->toBe([]);
});

it('picks the next stage from the same category, nearest age band first, curated first', function (): void {
    $category = RecoKit::category();
    $source = RecoKit::product('1000.00', 10, ['primary_category_id' => $category->id, 'age_min_months' => 12, 'age_max_months' => 24]);
    $far = RecoKit::product('900.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 48, 'age_max_months' => 72]);
    $near = RecoKit::product('900.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 24, 'age_max_months' => 36]);
    $filed = RecoKit::product('900.00', 5, ['age_min_months' => 36, 'age_max_months' => 48]);
    $filed->categories()->attach($category->id);
    RecoKit::product('900.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 18, 'age_max_months' => 30]); // overlaps: not a next stage
    RecoKit::product('900.00', 5, ['age_min_months' => 24, 'age_max_months' => 36]); // other category
    RecoKit::product('900.00', 0, ['primary_category_id' => $category->id, 'age_min_months' => 24, 'age_max_months' => 36]); // sold out
    $curated = RecoKit::product('900.00', 5, ['age_min_months' => 0, 'age_max_months' => 6]);
    RecoKit::relate($source, $curated, RelationType::AgeProgression);

    expect(recoIds(recoPdp($source), 'age_progression'))->toBe([$curated->id, $near->id, $filed->id, $far->id]);
});

it('has no computed next stage without an upper age', function (): void {
    $category = RecoKit::category();
    $source = RecoKit::product('1000.00', 10, ['primary_category_id' => $category->id, 'age_min_months' => 12, 'age_max_months' => null]);
    RecoKit::product('900.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 24]);

    expect(recoIds(recoPdp($source), 'age_progression'))->toBe([]);
});

it('builds frequently bought together with variant ids and the bundle total', function (): void {
    $source = RecoKit::product('1000.00');
    $a = RecoKit::product('250.00');
    $b = RecoKit::product('100.00');
    $c = RecoKit::product('50.00');
    RecoKit::copurchase($source, $a, 9);
    RecoKit::copurchase($source, $b, 4);
    RecoKit::copurchase($source, $c, 3); // third co-purchase: over the 2-item cap

    $fbt = recoPdp($source)->json('recommendations.fbt');

    expect(array_column($fbt['items'], 'id'))->toBe([$source->id, $a->id, $b->id])
        ->and(array_column($fbt['items'], 'variant_id'))->toBe([$source->variants()->value('id'), $a->variants()->value('id'), $b->variants()->value('id')])
        ->and(array_column($fbt['items'], 'unit_price'))->toBe(['1000.00', '250.00', '100.00'])
        ->and($fbt['bundle_total'])->toBe('1350.00');
});

it('uses the default variant of a multi-variant product, and skips one without a default', function (): void {
    $source = RecoKit::twoVariants(default: 1);
    $noDefault = RecoKit::twoVariants(default: null);
    $simple = RecoKit::product('200.00');
    RecoKit::copurchase($source, $noDefault, 10);
    RecoKit::copurchase($source, $simple, 5);

    $fbt = recoPdp($source)->json('recommendations.fbt');
    $defaultId = $source->variants()->where('is_default', true)->value('id');

    expect(array_column($fbt['items'], 'id'))->toBe([$source->id, $simple->id])
        ->and($fbt['items'][0]['variant_id'])->toBe($defaultId)
        ->and($fbt['bundle_total'])->toBe('1300.00');
});

it('has no frequently-bought-together block when it cannot be shown whole', function (string $case): void {
    $source = RecoKit::product('1000.00', $case === 'source sold out' ? 0 : 10);
    $other = RecoKit::product('300.00', $case === 'partner sold out' ? 0 : 10);
    RecoKit::copurchase($source, $other, $case === 'too few orders' ? 2 : 3);
    if ($case === 'source default sold out') {
        $source = RecoKit::twoVariants(default: 0);
        RecoKit::copurchase($source, $other, 5);
        DB::table('stock_levels')->where('variant_id', $source->variants()->where('is_default', true)->value('id'))->update(['on_hand' => 0]);
    }

    // A sold-out product still has a page; it just gets no bundle.
    expect(recoPdp($source)->json('recommendations.fbt'))->toBeNull();
})->with(['too few orders', 'partner sold out', 'source sold out', 'source default sold out']);

it('runs a bounded number of queries whatever the number of relations', function (): void {
    $measure = function (int $n): int {
        $category = RecoKit::category();
        $source = RecoKit::product('1000.00', 10, ['primary_category_id' => $category->id, 'age_min_months' => 12, 'age_max_months' => 24]);
        for ($i = 0; $i < $n; $i++) {
            RecoKit::relate($source, RecoKit::product('2000.00'), RelationType::Upsell, $i);
            RecoKit::relate($source, RecoKit::product('300.00'), RelationType::CrossSell, $i);
            RecoKit::relate($source, RecoKit::product('900.00'), RelationType::AgeProgression, $i);
            RecoKit::copurchase($source, RecoKit::product('100.00'), 5 + $i);
            RecoKit::product('900.00', 5, ['primary_category_id' => $category->id, 'age_min_months' => 24 + $i]);
        }
        $product = app(ProductDetail::class)->find($source->slug);
        app(FlashSaleLookup::class)->byVariant(); // memoised per request: warm it so both runs compare alike

        DB::flushQueryLog();
        DB::enableQueryLog();
        $recommendations = app(RecommendationResolver::class)->forProduct($product);
        (new App\Http\Resources\Storefront\ProductRecommendationsResource($recommendations))->resolve(request());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $measure(2);
    $large = $measure(8);

    expect($large)->toBe($small)->and($small)->toBeLessThanOrEqual(12);
});
