<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\RelationData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\DTOs\VariantDefaultsData;
use App\Domain\Catalog\DTOs\VariantOptionData;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Demo products (P3-DAT-04) for local development and E2E — local/testing only,
 * called from DatabaseSeeder behind the environment check. Bangla and English names
 * on every product (DATABASE-DESIGN §7), realistic BDT prices, age ranges in months,
 * simple and Color/Size variable products, a few curated relations, and image rows
 * with no file (`path` null) until the media pipeline (P4-CAT-07) exists.
 *
 * Written through CreateProduct, so the demo data obeys the same rules as admin
 * writes. Idempotent on slug: an existing product is left as it is. ProductChanged is
 * silenced while seeding — a fresh database has no storefront cache to purge.
 *
 * Needs the brands (CatalogDemoSeeder), categories (CategoryDemoSeeder) and the
 * Color/Size attributes (CatalogReferenceSeeder); it runs them first if missing.
 */
class DemoCatalogSeeder extends Seeder
{
    /**
     * slug → [name, name_bn, brand slug|null, primary category slug, extra category slugs, age min, age max,
     *         gender|null, educational, short description, short description bn, price|null, compare-at|null, cost,
     *         weight g, variable options (attribute slug → value slugs), per-combination prices]
     *
     * @var array<string, array{name: string, name_bn: string, brand: string|null, category: string, also?: list<string>, age: array{int, int}, gender?: Gender, educational?: bool, short: string, short_bn: string, price: string, compare_at?: string, cost: string, weight: int, options?: array<string, list<string>>, prices?: array<string, string>, featured?: bool}>
     */
    private const array PRODUCTS = [
        'lego-classic-creative-brick-box' => [
            'name' => 'LEGO Classic Creative Brick Box', 'name_bn' => 'লেগো ক্লাসিক ক্রিয়েটিভ ব্রিক বক্স',
            'brand' => 'lego', 'category' => 'building-blocks', 'also' => ['stem-kits'], 'age' => [48, 144], 'educational' => true,
            'short' => '790 colourful bricks for open-ended building.', 'short_bn' => 'ইচ্ছেমতো বানানোর জন্য 790টি রঙিন ব্রিক।',
            'price' => '4950.00', 'compare_at' => '5500.00', 'cost' => '3650.00', 'weight' => 1450, 'featured' => true,
        ],
        'lego-duplo-number-train' => [
            'name' => 'LEGO DUPLO My First Number Train', 'name_bn' => 'লেগো ডুপ্লো আমার প্রথম নম্বর ট্রেন',
            'brand' => 'lego', 'category' => 'building-blocks', 'also' => ['baby-toddler'], 'age' => [18, 36], 'educational' => true,
            'short' => 'Big bricks and numbers 1–10 for little hands.', 'short_bn' => 'ছোট হাতের জন্য বড় ব্রিক আর 1–10 সংখ্যা।',
            'price' => '2850.00', 'cost' => '2050.00', 'weight' => 700,
        ],
        'hot-wheels-5-car-gift-pack' => [
            'name' => 'Hot Wheels 5-Car Gift Pack', 'name_bn' => 'হট হুইলস 5-গাড়ির গিফট প্যাক',
            'brand' => 'hot-wheels', 'category' => 'die-cast-cars', 'age' => [36, 96],
            'short' => 'Five 1:64 die-cast cars in one box.', 'short_bn' => 'এক বাক্সে পাঁচটি 1:64 ডাই-কাস্ট গাড়ি।',
            'price' => '1150.00', 'cost' => '780.00', 'weight' => 250,
        ],
        'hot-wheels-track-builder-set' => [
            'name' => 'Hot Wheels Track Builder Stunt Set', 'name_bn' => 'হট হুইলস ট্র্যাক বিল্ডার স্টান্ট সেট',
            'brand' => 'hot-wheels', 'category' => 'die-cast-cars', 'age' => [48, 120],
            'short' => 'Loops, ramps and a launcher, with one car.', 'short_bn' => 'লুপ, র‍্যাম্প আর লঞ্চার, সঙ্গে একটি গাড়ি।',
            'price' => '3450.00', 'compare_at' => '3900.00', 'cost' => '2500.00', 'weight' => 1100,
        ],
        'remote-control-racing-car' => [
            'name' => 'Remote Control Racing Car', 'name_bn' => 'রিমোট কন্ট্রোল রেসিং কার',
            'brand' => null, 'category' => 'remote-control', 'also' => ['vehicles'], 'age' => [72, 168],
            'short' => 'Rechargeable 1:18 RC car, 2.4 GHz remote.', 'short_bn' => 'চার্জেবল 1:18 আরসি গাড়ি, 2.4 GHz রিমোট।',
            'price' => '2250.00', 'cost' => '1450.00', 'weight' => 900, 'featured' => true,
            'options' => ['color' => ['red', 'blue', 'black']],
            'prices' => ['black' => '2350.00'],
        ],
        'barbie-fashion-doll' => [
            'name' => 'Barbie Fashion Doll', 'name_bn' => 'বার্বি ফ্যাশন পুতুল',
            'brand' => 'barbie', 'category' => 'dolls-playsets', 'age' => [36, 120], 'gender' => Gender::Girls,
            'short' => 'Poseable doll with a change of outfit.', 'short_bn' => 'পোশাক বদলানো যায় এমন পুতুল।',
            'price' => '1890.00', 'compare_at' => '2190.00', 'cost' => '1300.00', 'weight' => 350,
            'options' => ['color' => ['pink', 'purple']],
        ],
        'funskool-ludo-snakes-ladders' => [
            'name' => 'Funskool Ludo and Snakes & Ladders', 'name_bn' => 'ফানস্কুল লুডু ও সাপ-লুডু',
            'brand' => 'funskool', 'category' => 'educational-toys', 'age' => [60, 216], 'gender' => Gender::Unisex,
            'short' => 'Two classic family board games in one.', 'short_bn' => 'এক বোর্ডে দুটি পারিবারিক খেলা।',
            'price' => '450.00', 'cost' => '290.00', 'weight' => 400,
        ],
        'funskool-100-piece-jigsaw-puzzle' => [
            'name' => 'Funskool 100-Piece Jigsaw Puzzle', 'name_bn' => 'ফানস্কুল 100 পিসের জিগস পাজল',
            'brand' => 'funskool', 'category' => 'jigsaw-puzzles', 'age' => [60, 144], 'educational' => true,
            'short' => 'A 100-piece puzzle of Bangladesh\'s wildlife.', 'short_bn' => 'বাংলাদেশের বন্যপ্রাণীর 100 পিসের পাজল।',
            'price' => '650.00', 'cost' => '420.00', 'weight' => 300,
        ],
        'fisher-price-rock-a-stack' => [
            'name' => 'Fisher-Price Rock-a-Stack', 'name_bn' => 'ফিশার-প্রাইস রক-আ-স্ট্যাক',
            'brand' => 'fisher-price', 'category' => 'stacking-toys', 'also' => ['baby-toddler'], 'age' => [6, 24], 'educational' => true,
            'short' => 'The classic rocking ring stacker.', 'short_bn' => 'দোলানো যায় এমন ক্লাসিক রিং স্ট্যাকার।',
            'price' => '890.00', 'cost' => '600.00', 'weight' => 450,
        ],
        'fisher-price-baby-rattle-set' => [
            'name' => 'Fisher-Price Baby Rattle Set', 'name_bn' => 'ফিশার-প্রাইস বেবি ঝুনঝুনি সেট',
            'brand' => 'fisher-price', 'category' => 'rattles-teethers', 'also' => ['baby-toddler'], 'age' => [3, 18],
            'short' => 'Three BPA-free rattles and teethers.', 'short_bn' => 'তিনটি বিপিএ-মুক্ত ঝুনঝুনি ও টিদার।',
            'price' => '750.00', 'cost' => '480.00', 'weight' => 300,
        ],
        'play-doh-starter-set' => [
            'name' => 'Play-Doh Starter Set', 'name_bn' => 'প্লে-ডো স্টার্টার সেট',
            'brand' => 'play-doh', 'category' => 'arts-crafts', 'age' => [36, 96],
            'short' => 'Six tubs of dough with shape cutters.', 'short_bn' => 'শেপ কাটারসহ ছয় কৌটা ডো।',
            'price' => '990.00', 'cost' => '650.00', 'weight' => 650,
        ],
        'kids-tricycle' => [
            'name' => 'Kids Tricycle', 'name_bn' => 'বাচ্চাদের ট্রাইসাইকেল',
            'brand' => null, 'category' => 'vehicles', 'age' => [24, 72],
            'short' => 'Steel-frame tricycle with a parent push bar.', 'short_bn' => 'অভিভাবকের পুশ বারসহ স্টিল ফ্রেমের ট্রাইসাইকেল।',
            'price' => '3800.00', 'cost' => '2600.00', 'weight' => 6500,
            'options' => ['color' => ['red', 'blue', 'yellow'], 'size' => ['small', 'medium', 'large']],
            'prices' => ['medium' => '4300.00', 'large' => '4900.00'],
        ],
        'stem-solar-robot-kit' => [
            'name' => 'STEM Solar Robot Kit', 'name_bn' => 'স্টেম সোলার রোবট কিট',
            'brand' => null, 'category' => 'stem-kits', 'also' => ['educational-toys'], 'age' => [96, 168], 'educational' => true,
            'short' => 'Build 12 solar-powered robots from one kit.', 'short_bn' => 'এক কিটে 12টি সৌরচালিত রোবট বানাও।',
            'price' => '1650.00', 'compare_at' => '1950.00', 'cost' => '1050.00', 'weight' => 600,
        ],
    ];

    /**
     * [source slug, target slug, type, reason]. Upsell targets cost more (rule 25c).
     *
     * @var list<array{string, string, RelationType, string}>
     */
    private const array RELATIONS = [
        ['hot-wheels-5-car-gift-pack', 'hot-wheels-track-builder-set', RelationType::Upsell, 'Cars need a track.'],
        ['lego-duplo-number-train', 'lego-classic-creative-brick-box', RelationType::AgeProgression, 'DUPLO graduates to Classic at 4+.'],
        ['fisher-price-rock-a-stack', 'lego-duplo-number-train', RelationType::AgeProgression, 'Stacker at 6–24m, DUPLO from 18m.'],
        ['play-doh-starter-set', 'funskool-100-piece-jigsaw-puzzle', RelationType::CrossSell, 'Rainy-day indoor play.'],
    ];

    public function run(): void
    {
        $this->ensurePrerequisites();

        Event::fakeFor(function (): void {
            $create = app(CreateProduct::class);

            foreach (array_keys(self::PRODUCTS) as $slug) {
                if (Product::withTrashed()->where('slug', $slug)->exists()) {
                    continue;
                }

                $product = $create->handle($this->document($slug));
                $this->images($product, self::PRODUCTS[$slug]['name'], self::PRODUCTS[$slug]['name_bn']);
            }

            $this->relations();
        }, [ProductChanged::class]);
    }

    private function ensurePrerequisites(): void
    {
        if (! ProductAttributeValue::query()->whereHas('attribute', fn ($q) => $q->where('slug', 'size'))->exists()) {
            $this->call(CatalogReferenceSeeder::class);
        }
        if (! Category::query()->where('slug', 'building-blocks')->exists()) {
            $this->call(CategoryDemoSeeder::class);
        }
        if (! Brand::query()->where('slug', 'lego')->exists()) {
            $this->call(CatalogDemoSeeder::class);
        }
    }

    private function document(string $slug, bool $withRelations = false): ProductData
    {
        $p = self::PRODUCTS[$slug];
        $categoryIds = array_map(self::categoryId(...), [$p['category'], ...($p['also'] ?? [])]);

        $options = [];
        $variants = [];
        $variable = isset($p['options']);

        if ($variable) {
            $valuesByAttribute = [];
            foreach ($p['options'] as $attributeSlug => $valueSlugs) {
                $values = array_map(static fn (string $v): ProductAttributeValue => self::value($attributeSlug, $v), $valueSlugs);
                $valuesByAttribute[] = $values;
                $options[] = new VariantOptionData($values[0]->attribute_id, array_map(static fn (ProductAttributeValue $v): int => $v->id, $values));
            }

            // A per-value price ("black" => 2350) applies to every combination using that value.
            foreach (self::cartesian($valuesByAttribute) as $combination) {
                $override = null;
                foreach ($combination as $value) {
                    $override = $p['prices'][$value->slug] ?? $override;
                }
                if ($override !== null) {
                    $variants[] = new VariantData(
                        attributeValueIds: array_map(static fn (ProductAttributeValue $v): int => $v->id, $combination),
                        price: Money::of($override),
                    );
                }
            }
        } else {
            $variants[] = new VariantData(
                price: Money::of($p['price']),
                compareAtPrice: isset($p['compare_at']) ? Money::of($p['compare_at']) : null,
                costPrice: Money::of($p['cost']),
                weightGrams: $p['weight'],
            );
        }

        return new ProductData(
            name: $p['name'],
            type: $variable ? ProductType::Variable : ProductType::Simple,
            primaryCategoryId: $categoryIds[0],
            categoryIds: $categoryIds,
            nameBn: $p['name_bn'],
            slug: $slug,
            brandId: $p['brand'] !== null ? Brand::query()->where('slug', $p['brand'])->value('id') : null,
            shortDescription: $p['short'],
            shortDescriptionBn: $p['short_bn'],
            description: $p['short'].' Safe, durable and tested for children in the stated age range.',
            descriptionBn: $p['short_bn'].' নির্ধারিত বয়সের শিশুদের জন্য নিরাপদ ও টেকসই।',
            ageMinMonths: $p['age'][0],
            ageMaxMonths: $p['age'][1],
            gender: $p['gender'] ?? null,
            isEducational: $p['educational'] ?? false,
            safetyNote: $p['age'][0] < 36 ? null : 'Contains small parts. Not for children under 3 years.',
            safetyNoteBn: $p['age'][0] < 36 ? null : 'ছোট অংশ আছে। 3 বছরের কম বয়সী শিশুর জন্য নয়।',
            status: ProductStatus::Active,
            isFeatured: $p['featured'] ?? false,
            options: $options,
            variants: $variants,
            variantDefaults: $variable ? new VariantDefaultsData(
                price: Money::of($p['price']),
                compareAtPrice: isset($p['compare_at']) ? Money::of($p['compare_at']) : null,
                costPrice: Money::of($p['cost']),
                weightGrams: $p['weight'],
            ) : null,
            relations: $withRelations ? $this->relationsFor($slug) : null,
        );
    }

    /** Relations go in a second pass, once every target exists. */
    private function relations(): void
    {
        foreach (array_unique(array_column(self::RELATIONS, 0)) as $slug) {
            $product = Product::query()->where('slug', $slug)->first();

            if ($product === null || $product->productRelations()->exists()) {
                continue;
            }

            app(UpdateProduct::class)->handle($product, $this->document($slug, withRelations: true));
        }
    }

    /** @return list<RelationData> */
    private function relationsFor(string $slug): array
    {
        $relations = [];

        foreach (self::RELATIONS as $position => [$source, $target, $type, $reason]) {
            if ($source !== $slug) {
                continue;
            }

            $targetId = Product::query()->where('slug', $target)->value('id');
            if (is_int($targetId)) {
                $relations[] = new RelationData($targetId, $type, $position, $reason);
            }
        }

        return $relations;
    }

    /** Two placeholder image rows per product: no file yet, alt text in both languages. */
    private function images(Product $product, string $name, string $nameBn): void
    {
        foreach ([0, 1] as $position) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => null,
                'alt_text' => $position === 0 ? $name : $name.' — in the box',
                'alt_text_bn' => $position === 0 ? $nameBn : $nameBn.' — বাক্সে',
                'position' => $position,
                'is_primary' => $position === 0,
            ]);
        }
    }

    private static function categoryId(string $slug): int
    {
        $id = Category::query()->where('slug', $slug)->value('id');

        if (! is_int($id)) {
            throw new RuntimeException("Demo category [{$slug}] is missing; run CategoryDemoSeeder.");
        }

        return $id;
    }

    private static function value(string $attributeSlug, string $valueSlug): ProductAttributeValue
    {
        return ProductAttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where('slug', $attributeSlug))
            ->where('slug', $valueSlug)
            ->firstOr(fn () => throw new RuntimeException("Attribute value [{$attributeSlug}:{$valueSlug}] is missing; run CatalogReferenceSeeder."));
    }

    /**
     * @param  list<list<ProductAttributeValue>>  $sets
     * @return list<list<ProductAttributeValue>>
     */
    private static function cartesian(array $sets): array
    {
        $result = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($result as $prefix) {
                foreach ($set as $value) {
                    $next[] = [...$prefix, $value];
                }
            }
            $result = $next;
        }

        return $result;
    }
}
