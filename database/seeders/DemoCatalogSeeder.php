<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Actions\UploadProductImage;
use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\RelationData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\DTOs\VariantDefaultsData;
use App\Domain\Catalog\DTOs\VariantOptionData;
use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Demo products (P3-DAT-04) for local development and E2E — local/testing only,
 * called from DatabaseSeeder behind the environment check.
 *
 * The catalogue is the approved design's: the 16 products of
 * docs/_design/03-storefront-spec.md PART C (C1 names, brands, ages in months,
 * prices, compare-at, ratings and `TMB-…` SKUs; C1.2 variant axes), so the storefront
 * and admin look like the Figma frames. Bestsellers (C1.1) are the featured products,
 * in their bestseller order under the default `featured` sort; the "New" eight are
 * published within the last 21 days, newest first in C1.1 order.
 *
 * Images: each product's Figma photo (docs/_design/assets/products/NN-{SKU}.png) is
 * attached as its primary image through UploadProductImage — the same validation,
 * re-encode and renditions as an admin upload (minus the C2PA chunk; see
 * attachImage) — with English and Bangla alt text,
 * and renditions generated synchronously so a fresh seed needs no queue worker.
 * Controlled by `media.demo_seed_images` (DEMO_SEED_IMAGES): on in `local`, off
 * elsewhere, so PHPUnit and the E2E seeds stay fast.
 *
 * Written through CreateProduct, so the demo data obeys the same rules as admin
 * writes. Idempotent on slug: an existing product is left as it is (it only gains
 * its photo if it has no image yet and images are on). ProductChanged is silenced
 * while seeding — a fresh database has no storefront cache to purge.
 *
 * Needs the brands (BrandDemoSeeder), categories (CategoryDemoSeeder) and the
 * Color/Size attributes (CatalogReferenceSeeder); it runs them first if missing.
 */
class DemoCatalogSeeder extends Seeder
{
    /** Where the Figma product exports live: `NN-{SKU}.png`. */
    public const string IMAGE_DIR = 'docs/_design/assets/products';

    /**
     * slug → product. `category` is the primary (an L2 MegaMenu child); `also` adds
     * more categories (always the L1 parent). Variable products list every
     * combination in `variants`; the one marked `default` carries the spec SKU,
     * price and compare-at (the PDP's preselected variant).
     *
     * @var array<string, array{name: string, name_bn: string, brand: string, category: string, also: list<string>, age: array{int, int}, price: string, compare_at?: string, rating: array{string, int}, sku: string, weight: int, short: string, short_bn: string, educational?: bool, variants?: list<array{values: array<string, string>, price: string, compare_at?: string, default?: true}>}>
     */
    public const array PRODUCTS = [
        'lego-classic-creative-bricks-484' => [
            'name' => 'LEGO Classic Creative Bricks 484 pcs', 'name_bn' => 'লেগো ক্লাসিক ক্রিয়েটিভ ব্রিকস 484 পিস',
            'brand' => 'lego', 'category' => 'lego-sets', 'also' => ['building-blocks'], 'age' => [48, 144], 'educational' => true,
            'price' => '4250.00', 'compare_at' => '4990.00', 'rating' => ['4.70', 42], 'sku' => 'TMB-LEG-CL484', 'weight' => 1450,
            'short' => '484 colourful bricks in 35 shapes, with a base plate and wheels.', 'short_bn' => '35টি আকারের 484টি রঙিন ব্রিক, বেস প্লেট ও চাকা সহ।',
            'variants' => [
                ['values' => ['set' => '90-pcs', 'packaging' => 'box'], 'price' => '1450.00'],
                ['values' => ['set' => '90-pcs', 'packaging' => 'tub'], 'price' => '1450.00'],
                ['values' => ['set' => '213-pcs', 'packaging' => 'box'], 'price' => '2650.00'],
                ['values' => ['set' => '213-pcs', 'packaging' => 'tub'], 'price' => '2650.00'],
                ['values' => ['set' => '484-pcs', 'packaging' => 'box'], 'price' => '4250.00', 'compare_at' => '4990.00', 'default' => true],
                ['values' => ['set' => '484-pcs', 'packaging' => 'tub'], 'price' => '4250.00', 'compare_at' => '4990.00'],
            ],
        ],
        'fisher-price-smart-stages-puppy' => [
            'name' => 'Fisher-Price Laugh & Learn Smart Stages Puppy', 'name_bn' => 'ফিশার-প্রাইস স্মার্ট স্টেজেস মিউজিক্যাল পাপি',
            'brand' => 'fisher-price', 'category' => 'musical-toys', 'also' => ['baby-toddler'], 'age' => [6, 36], 'educational' => true,
            'price' => '3180.00', 'compare_at' => '3650.00', 'rating' => ['4.80', 31], 'sku' => 'TMB-FIS-SSPUP', 'weight' => 650,
            'short' => 'A singing puppy that teaches first words, colours and counting.', 'short_bn' => 'গান গেয়ে প্রথম শব্দ, রং আর গোনা শেখায় এমন পাপি।',
        ],
        'hot-wheels-10-car-gift-pack' => [
            'name' => 'Hot Wheels 10-Car Gift Pack', 'name_bn' => 'হট হুইলস 10টি গাড়ির গিফট প্যাক',
            'brand' => 'hot-wheels', 'category' => 'die-cast-cars', 'also' => ['cars-rc'], 'age' => [36, 120],
            'price' => '1790.00', 'rating' => ['4.60', 58], 'sku' => 'TMB-HOT-10PK', 'weight' => 450,
            'short' => 'Ten 1:64 die-cast cars in one gift box.', 'short_bn' => 'এক গিফট বক্সে দশটি 1:64 ডাই-কাস্ট গাড়ি।',
            'variants' => [
                ['values' => ['set' => '5-cars'], 'price' => '950.00'],
                ['values' => ['set' => '10-cars'], 'price' => '1790.00', 'default' => true],
                ['values' => ['set' => '20-cars'], 'price' => '3350.00'],
            ],
        ],
        'hape-pound-tap-bench-xylophone' => [
            'name' => 'Hape Pound & Tap Bench with Xylophone', 'name_bn' => 'হেপ পাউন্ড অ্যান্ড ট্যাপ বেঞ্চ (জাইলোফোন সহ)',
            'brand' => 'hape', 'category' => 'musical-toys', 'also' => ['baby-toddler'], 'age' => [12, 36],
            'price' => '2950.00', 'compare_at' => '3400.00', 'rating' => ['4.90', 27], 'sku' => 'TMB-HAP-PTB', 'weight' => 1100,
            'short' => 'Pound the balls through, then play the xylophone that slides out.', 'short_bn' => 'বল ঠুকে নামাও, তারপর বের করে আনা জাইলোফোন বাজাও।',
        ],
        'mideer-wooden-shape-sorting-puzzle' => [
            'name' => 'Mideer Wooden Shape Sorting Puzzle', 'name_bn' => 'মিডির কাঠের শেপ সর্টিং পাজল',
            'brand' => 'mideer', 'category' => 'wooden-puzzles', 'also' => ['puzzles-games'], 'age' => [18, 48], 'educational' => true,
            'price' => '1250.00', 'rating' => ['4.50', 19], 'sku' => 'TMB-MID-SHSORT', 'weight' => 700,
            'short' => 'Chunky wooden shapes to match, sort and name.', 'short_bn' => 'মিলিয়ে, সাজিয়ে আর নাম বলে খেলার মোটা কাঠের আকার।',
        ],
        'huanger-baby-activity-gym-play-mat' => [
            'name' => 'Huanger Baby Activity Gym & Play Mat', 'name_bn' => 'হুয়াংগার বেবি অ্যাক্টিভিটি জিম ও প্লে ম্যাট',
            'brand' => 'huanger', 'category' => 'activity-gyms', 'also' => ['baby-toddler'], 'age' => [0, 12],
            'price' => '2390.00', 'compare_at' => '2850.00', 'rating' => ['4.40', 36], 'sku' => 'TMB-HUA-GYM', 'weight' => 1300,
            'short' => 'A soft play mat with hanging toys for tummy time.', 'short_bn' => 'টামি টাইমের জন্য ঝোলানো খেলনাসহ নরম প্লে ম্যাট।',
        ],
        'bruder-man-tga-dump-truck' => [
            'name' => 'Bruder MAN TGA Dump Truck 1:16', 'name_bn' => 'ব্রুডার MAN TGA ডাম্প ট্রাক 1:16',
            'brand' => 'bruder', 'category' => 'trucks-construction', 'also' => ['cars-rc'], 'age' => [36, 144],
            'price' => '5650.00', 'rating' => ['4.90', 14], 'sku' => 'TMB-BRU-MANDT', 'weight' => 2600,
            'short' => 'A 1:16 tipper truck with a working dump body and tailgate.', 'short_bn' => 'চালু ডাম্প বডি ও টেইলগেটসহ 1:16 টিপার ট্রাক।',
        ],
        'rubiks-cube-3x3-original' => [
            'name' => "Rubik's Cube 3×3 Original", 'name_bn' => 'রুবিক্স কিউব 3×3 অরিজিনাল',
            'brand' => 'rubiks', 'category' => 'cubes-brain-teasers', 'also' => ['puzzles-games'], 'age' => [96, 192], 'educational' => true,
            'price' => '890.00', 'compare_at' => '1150.00', 'rating' => ['4.70', 73], 'sku' => 'TMB-RUB-3X3', 'weight' => 150,
            'short' => 'The original 3×3 puzzle cube with smooth turning.', 'short_bn' => 'মসৃণভাবে ঘোরে এমন আসল 3×3 পাজল কিউব।',
        ],
        'play-doh-rainbow-starter-10-pack' => [
            'name' => 'Play-Doh Rainbow Starter 10-Pack', 'name_bn' => 'প্লে-ডো রেইনবো স্টার্টার 10 প্যাক',
            'brand' => 'play-doh', 'category' => 'clay-dough', 'also' => ['art-craft'], 'age' => [24, 96],
            'price' => '1120.00', 'rating' => ['4.60', 45], 'sku' => 'TMB-PLA-RB10', 'weight' => 900,
            'short' => 'Ten tubs of non-toxic modelling dough in rainbow colours.', 'short_bn' => 'রংধনু রঙের দশ কৌটা বিষমুক্ত মডেলিং ডো।',
        ],
        'barbie-dreamhouse-pool-party-doll' => [
            'name' => 'Barbie Dreamhouse Pool Party Doll', 'name_bn' => 'বার্বি ড্রিমহাউস পুল পার্টি ডল',
            'brand' => 'barbie', 'category' => 'fashion-dolls', 'also' => ['dolls-soft-toys'], 'age' => [36, 120],
            'price' => '2480.00', 'compare_at' => '2950.00', 'rating' => ['4.30', 22], 'sku' => 'TMB-BAR-DHPP', 'weight' => 400,
            'short' => 'A poseable doll with a swimsuit, float and pool accessories.', 'short_bn' => 'সুইমস্যুট, ফ্লোট ও পুলের সরঞ্জামসহ পোজ দেওয়া যায় এমন পুতুল।',
        ],
        'clementoni-galileo-my-first-microscope' => [
            'name' => 'Clementoni Galileo My First Microscope', 'name_bn' => 'ক্লেমেন্টনি গ্যালিলিও মাই ফার্স্ট মাইক্রোস্কোপ',
            'brand' => 'clementoni', 'category' => 'microscopes-telescopes', 'also' => ['science-learning'], 'age' => [72, 144], 'educational' => true,
            'price' => '3890.00', 'rating' => ['4.50', 11], 'sku' => 'TMB-CLE-MICRO', 'weight' => 950,
            'short' => 'A real lit microscope with slides and a guide to first experiments.', 'short_bn' => 'স্লাইড ও প্রথম পরীক্ষার গাইডসহ আলোযুক্ত আসল মাইক্রোস্কোপ।',
        ],
        'little-tikes-first-slide' => [
            'name' => 'Little Tikes First Slide', 'name_bn' => 'লিটল টাইকস ফার্স্ট স্লাইড',
            'brand' => 'little-tikes', 'category' => 'slides-swings', 'also' => ['outdoor-ride-on'], 'age' => [18, 60],
            'price' => '7450.00', 'compare_at' => '8900.00', 'rating' => ['4.80', 17], 'sku' => 'TMB-LIT-SLIDE', 'weight' => 6800,
            'short' => 'A sturdy first slide for indoors or out, with easy-grip steps.', 'short_bn' => 'ঘরে বা বাইরে, সহজে ধরা যায় এমন সিঁড়িসহ মজবুত প্রথম স্লাইড।',
            'variants' => [
                ['values' => ['color' => 'red-blue'], 'price' => '7450.00', 'compare_at' => '8900.00', 'default' => true],
                ['values' => ['color' => 'green-yellow'], 'price' => '7450.00', 'compare_at' => '8900.00'],
                ['values' => ['color' => 'pink'], 'price' => '7450.00', 'compare_at' => '8900.00'],
            ],
        ],
        'intex-dinosaur-inflatable-kids-pool' => [
            'name' => 'Intex Dinosaur Inflatable Kids Pool', 'name_bn' => 'ইনটেক্স ডাইনোসর ইনফ্ল্যাটেবল কিডস পুল',
            'brand' => 'intex', 'category' => 'pools-water-play', 'also' => ['outdoor-ride-on'], 'age' => [24, 72],
            'price' => '1650.00', 'rating' => ['4.20', 29], 'sku' => 'TMB-INT-DINOP', 'weight' => 1800,
            'short' => 'A dinosaur-shaped paddling pool with a soft inflatable floor.', 'short_bn' => 'নরম ইনফ্ল্যাটেবল মেঝেসহ ডাইনোসর আকারের কিডস পুল।',
            'variants' => [
                ['values' => ['size' => 'small'], 'price' => '1650.00', 'default' => true],
                ['values' => ['size' => 'large'], 'price' => '1650.00'],
            ],
        ],
        'crayola-washable-super-tips-20-markers' => [
            'name' => 'Crayola Washable Super Tips 20 Markers', 'name_bn' => 'ক্রেয়োলা ওয়াশেবল সুপার টিপস 20 মার্কার',
            'brand' => 'crayola', 'category' => 'paints-markers', 'also' => ['art-craft'], 'age' => [36, 120],
            'price' => '760.00', 'rating' => ['4.70', 51], 'sku' => 'TMB-CRA-ST20', 'weight' => 300,
            'short' => 'Washable markers that draw thick or thin lines.', 'short_bn' => 'মোটা বা চিকন দাগ টানা যায় এমন ধোয়া যায় মার্কার।',
            'variants' => [
                ['values' => ['set' => '12-markers'], 'price' => '520.00'],
                ['values' => ['set' => '20-markers'], 'price' => '760.00', 'default' => true],
                ['values' => ['set' => '50-markers'], 'price' => '1680.00'],
            ],
        ],
        'winfun-baby-walker-push-car' => [
            'name' => 'Winfun Baby Walker Push Car', 'name_bn' => 'উইনফান বেবি ওয়াকার পুশ কার',
            'brand' => 'winfun', 'category' => 'push-pull', 'also' => ['baby-toddler'], 'age' => [9, 24],
            'price' => '4120.00', 'compare_at' => '4750.00', 'rating' => ['4.40', 24], 'sku' => 'TMB-WIN-PUSHW', 'weight' => 2900,
            'short' => 'A push-along walker that turns into a ride-on car.', 'short_bn' => 'ঠেলে হাঁটার ওয়াকার, যা রাইড-অন গাড়ি হয়ে যায়।',
        ],
        'melissa-doug-wooden-doctor-kit' => [
            'name' => 'Melissa & Doug Wooden Doctor Kit', 'name_bn' => 'মেলিসা অ্যান্ড ডাগ কাঠের ডক্টর কিট',
            'brand' => 'melissa-doug', 'category' => 'role-play-sets', 'also' => ['dolls-soft-toys'], 'age' => [36, 96],
            'price' => '2760.00', 'rating' => ['4.60', 33], 'sku' => 'TMB-MEL-DOCK', 'weight' => 1000,
            'short' => 'A wooden stethoscope, thermometer and more in a carry case.', 'short_bn' => 'বহনযোগ্য কেসে কাঠের স্টেথোস্কোপ, থার্মোমিটার ও আরও কিছু।',
        ],
    ];

    /** C1.1 "Bestseller" (top 8 by units sold), in rank order: the featured products. */
    private const array BESTSELLERS = [
        'hot-wheels-10-car-gift-pack', 'lego-classic-creative-bricks-484', 'play-doh-rainbow-starter-10-pack',
        'fisher-price-smart-stages-puppy', 'crayola-washable-super-tips-20-markers', 'mideer-wooden-shape-sorting-puzzle',
        'hape-pound-tap-bench-xylophone', 'rubiks-cube-3x3-original',
    ];

    /** C1.1 "New" (added in the last 21 days), newest first. */
    private const array NEW_ARRIVALS = [
        'clementoni-galileo-my-first-microscope', 'bruder-man-tga-dump-truck', 'melissa-doug-wooden-doctor-kit',
        'intex-dinosaur-inflatable-kids-pool', 'huanger-baby-activity-gym-play-mat', 'winfun-baby-walker-push-car',
        'little-tikes-first-slide', 'barbie-dreamhouse-pool-party-doll',
    ];

    /**
     * The demo's own variant axes (C1.2) — not reference data, so they live here
     * rather than in CatalogReferenceSeeder. attribute slug → [name, name_bn, type,
     * values [value, value_bn, slug, hex?]].
     *
     * @var array<string, array{string, string, AttributeType, list<array{0: string, 1: string, 2: string, 3?: string}>}>
     */
    private const array DEMO_ATTRIBUTES = [
        'color' => ['Color', 'রং', AttributeType::Color, [
            ['Red & Blue', 'লাল-নীল', 'red-blue', '#E53935'],
            ['Green & Yellow', 'সবুজ-হলুদ', 'green-yellow', '#43A047'],
        ]],
        'set' => ['Set', 'সেট', AttributeType::Select, [
            ['90 pcs', '90 পিস', '90-pcs'], ['213 pcs', '213 পিস', '213-pcs'], ['484 pcs', '484 পিস', '484-pcs'],
            ['5 cars', '5টি', '5-cars'], ['10 cars', '10টি', '10-cars'], ['20 cars', '20টি', '20-cars'],
            ['12 markers', '12টি', '12-markers'], ['20 markers', '20টি', '20-markers'], ['50 markers', '50টি', '50-markers'],
        ]],
        'packaging' => ['Packaging', 'প্যাকিং', AttributeType::Select, [
            ['Box', 'বক্স', 'box'], ['Tub', 'টাব', 'tub'],
        ]],
    ];

    /**
     * [source slug, target slug, type, reason]. Upsell targets cost more (rule 25c).
     *
     * @var list<array{string, string, RelationType, string}>
     */
    private const array RELATIONS = [
        ['hot-wheels-10-car-gift-pack', 'bruder-man-tga-dump-truck', RelationType::Upsell, 'Car fans step up to a 1:16 truck.'],
        ['mideer-wooden-shape-sorting-puzzle', 'lego-classic-creative-bricks-484', RelationType::AgeProgression, 'Shape sorting at 18–48m, LEGO Classic from 4 years.'],
        ['huanger-baby-activity-gym-play-mat', 'fisher-price-smart-stages-puppy', RelationType::AgeProgression, 'Play gym to 12m, Smart Stages from 6m.'],
        ['play-doh-rainbow-starter-10-pack', 'crayola-washable-super-tips-20-markers', RelationType::CrossSell, 'Rainy-day art table.'],
    ];

    private ?CarbonImmutable $now = null;

    public function run(): void
    {
        $this->ensurePrerequisites();
        $withImages = self::imagesEnabled();

        Event::fakeFor(function () use ($withImages): void {
            $create = app(CreateProduct::class);

            foreach (self::PRODUCTS as $slug => $p) {
                $product = Product::withTrashed()->where('slug', $slug)->first();

                if ($product === null) {
                    $product = $create->handle($this->document($slug));
                    $this->finish($product, $slug);
                }

                if ($withImages && ! $product->trashed() && ! $product->images()->exists()) {
                    $this->attachImage($product, $p['sku'], $p['name'], $p['name_bn']);
                }
            }

            $this->relations();
        }, [ProductChanged::class]);
    }

    /** `media.demo_seed_images`, or — unset — on in `local` only. */
    public static function imagesEnabled(): bool
    {
        $flag = config('media.demo_seed_images');

        if ($flag === null || $flag === '') {
            return app()->environment('local');
        }

        return filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    /**
     * Attach the Figma photo for `$sku` as the product's image through the real upload
     * pipeline (UploadProductImage: content sniffing, payload scan, re-encode, private
     * master), with alt text in both languages, and generate its renditions
     * synchronously — the queue connection is `sync` for the call, so the after-commit
     * job runs before it returns.
     *
     * The exports carry a C2PA Content Credentials chunk (`caBX`) whose manifest embeds
     * an SVG icon, which the pipeline's payload scan rightly refuses. The upload is a
     * temporary copy with that one chunk dropped — every other chunk, and so every
     * pixel, byte-identical; the pipeline's re-encode strips all metadata anyway. The
     * source files are left untouched.
     */
    public function attachImage(Product $product, string $sku, string $alt, string $altBn): ProductImage
    {
        $files = glob(base_path(self::IMAGE_DIR)."/*-{$sku}.png") ?: [];

        if (count($files) !== 1) {
            throw new RuntimeException("Expected one Figma export for [{$sku}] in ".self::IMAGE_DIR.', found '.count($files).'.');
        }

        $upload = self::withoutContentCredentials($files[0]);
        $previous = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $image = app(UploadProductImage::class)->handle($product, $upload, ['alt_text' => $alt, 'alt_text_bn' => $altBn]);
        } finally {
            config(['queue.default' => $previous]);
            @unlink($upload);
        }

        return $image->refresh();
    }

    /** A temp copy of a PNG without its `caBX` (C2PA) chunks. */
    private static function withoutContentCredentials(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $signature = "\x89PNG\r\n\x1A\n";

        if (! str_starts_with($bytes, $signature)) {
            throw new RuntimeException("[{$path}] is not a PNG.");
        }

        $out = $signature;
        $offset = 8;
        $total = strlen($bytes);

        while ($offset + 12 <= $total) {
            /** @var array{1: int} $length */
            $length = unpack('N', substr($bytes, $offset, 4));
            $chunk = substr($bytes, $offset, 12 + $length[1]);

            if (substr($chunk, 4, 4) !== 'caBX') {
                $out .= $chunk;
            }

            $offset += 12 + $length[1];
        }

        $temp = tempnam(sys_get_temp_dir(), 'tmb-demo-');

        if ($temp === false || file_put_contents($temp, $out) === false) {
            throw new RuntimeException('Could not write a temporary demo image.');
        }

        return $temp;
    }

    private function ensurePrerequisites(): void
    {
        if (! ProductAttributeValue::query()->whereHas('attribute', fn ($q) => $q->where('slug', 'size'))->exists()) {
            $this->call(CatalogReferenceSeeder::class);
        }
        if (! Category::query()->where('slug', 'lego-sets')->exists()) {
            $this->call(CategoryDemoSeeder::class);
        }
        if (! Brand::query()->where('slug', 'melissa-doug')->exists()) {
            $this->call(BrandDemoSeeder::class);
        }

        $this->ensureAttributes();
    }

    /** Adds the demo axes; idempotent on slug, appending after existing values. */
    private function ensureAttributes(): void
    {
        $nextPosition = (int) ProductAttribute::query()->max('position') + 1;

        foreach (self::DEMO_ATTRIBUTES as $slug => [$name, $nameBn, $type, $values]) {
            $attribute = ProductAttribute::query()->firstOrCreate(['slug' => $slug], [
                'name' => $name, 'name_bn' => $nameBn, 'type' => $type,
                'is_variant_defining' => true, 'is_filterable' => true, 'position' => $nextPosition++,
            ]);

            foreach ($values as $value) {
                ProductAttributeValue::query()->firstOrCreate(['attribute_id' => $attribute->id, 'slug' => $value[2]], [
                    'value' => $value[0], 'value_bn' => $value[1], 'color_hex' => $value[3] ?? null,
                    'position' => (int) ProductAttributeValue::query()->where('attribute_id', $attribute->id)->max('position') + 1,
                ]);
            }
        }
    }

    private function document(string $slug, bool $withRelations = false): ProductData
    {
        $p = self::PRODUCTS[$slug];
        $categoryIds = array_map(self::categoryId(...), [$p['category'], ...$p['also']]);
        $price = Money::of($p['price']);

        $options = [];
        $variants = [];
        $variable = isset($p['variants']);

        if ($variable) {
            /** @var array<string, array<string, ProductAttributeValue>> $axes attribute slug → value slug → value */
            $axes = [];
            foreach ($p['variants'] as $row) {
                $values = [];
                foreach ($row['values'] as $attributeSlug => $valueSlug) {
                    $values[] = $axes[$attributeSlug][$valueSlug] ??= self::value($attributeSlug, $valueSlug);
                }

                $rowPrice = Money::of($row['price']);
                $variants[] = new VariantData(
                    attributeValueIds: array_map(static fn (ProductAttributeValue $v): int => $v->id, $values),
                    sku: isset($row['default']) ? $p['sku'] : null,
                    price: $rowPrice,
                    compareAtPrice: isset($row['compare_at']) ? Money::of($row['compare_at']) : null,
                    costPrice: $rowPrice->percentage(70),
                    weightGrams: $p['weight'],
                );
            }

            foreach ($axes as $values) {
                $first = reset($values);
                $options[] = new VariantOptionData($first->attribute_id, array_values(array_map(static fn (ProductAttributeValue $v): int => $v->id, $values)));
            }
        } else {
            $variants[] = new VariantData(
                sku: $p['sku'],
                price: $price,
                compareAtPrice: isset($p['compare_at']) ? Money::of($p['compare_at']) : null,
                costPrice: $price->percentage(70),
                weightGrams: $p['weight'],
            );
        }

        $underThree = $p['age'][0] < 36;

        return new ProductData(
            name: $p['name'],
            type: $variable ? ProductType::Variable : ProductType::Simple,
            primaryCategoryId: $categoryIds[0],
            categoryIds: $categoryIds,
            nameBn: $p['name_bn'],
            slug: $slug,
            brandId: self::brandId($p['brand']),
            shortDescription: $p['short'],
            shortDescriptionBn: $p['short_bn'],
            description: $p['short'].' Safe, durable and tested for children in the stated age range.',
            descriptionBn: $p['short_bn'].' নির্ধারিত বয়সের শিশুদের জন্য নিরাপদ ও টেকসই।',
            ageMinMonths: $p['age'][0],
            ageMaxMonths: $p['age'][1],
            isEducational: $p['educational'] ?? false,
            // A11: under 3 → the supervision line; 3+ → the small-parts warning.
            safetyNote: $underThree ? 'Small parts — not for under 3 without supervision.' : 'Contains small parts. Not for children under 3 years.',
            safetyNoteBn: $underThree ? '3 বছরের নিচে ছোট অংশ — বড়দের নজরদারি দরকার।' : 'ছোট অংশ আছে। 3 বছরের কম বয়সী শিশুর জন্য নয়।',
            status: ProductStatus::Active,
            isFeatured: in_array($slug, self::BESTSELLERS, true),
            publishedAt: $this->publishedAt($slug),
            options: $options,
            variants: $variants,
            variantDefaults: $variable ? new VariantDefaultsData(price: $price, costPrice: $price->percentage(70), weightGrams: $p['weight']) : null,
            relations: $withRelations ? $this->relationsFor($slug) : null,
        );
    }

    /**
     * "New" products: 1–8 days ago, newest first. Everything else: 30+ days ago, with
     * the bestsellers most recent among them, so the `featured` sort (is_featured,
     * then published_at desc) lists them in bestseller rank.
     */
    private function publishedAt(string $slug): CarbonImmutable
    {
        $now = $this->now ??= CarbonImmutable::now()->startOfMinute();
        $new = array_search($slug, self::NEW_ARRIVALS, true);

        if ($new !== false) {
            return $now->subDays($new + 1);
        }

        $older = [...self::BESTSELLERS, ...array_values(array_diff(array_keys(self::PRODUCTS), self::BESTSELLERS, self::NEW_ARRIVALS))];

        return $now->subDays(30 + (int) array_search($slug, $older, true));
    }

    /**
     * After the write: the spec's default variant becomes the default (VariantMatrix
     * picks the first combination; it keeps the current default on every later
     * write), and the C1 rating aggregate is set — demo data, as no reviews exist yet.
     */
    private function finish(Product $product, string $slug): void
    {
        $p = self::PRODUCTS[$slug];

        ProductVariant::query()->where('product_id', $product->id)->update(['is_default' => false]);
        ProductVariant::query()->where('product_id', $product->id)->where('sku', $p['sku'])->update(['is_default' => true]);

        Product::query()->whereKey($product->id)->update(['rating_avg' => $p['rating'][0], 'rating_count' => $p['rating'][1]]);
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

    private static function brandId(string $slug): int
    {
        $id = Brand::query()->where('slug', $slug)->value('id');

        if (! is_int($id)) {
            throw new RuntimeException("Demo brand [{$slug}] is missing; run BrandDemoSeeder.");
        }

        return $id;
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
}
