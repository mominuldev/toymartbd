<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * Demo catalog for local development and E2E (DATABASE-DESIGN §7: Bangla names in
 * every seed). Local/testing only — called from DatabaseSeeder behind an
 * environment check. Idempotent on slug.
 */
class BrandDemoSeeder extends Seeder
{
    /** @var list<array{name: string, name_bn: string, slug: string, description: string, description_bn: string, country: string}> */
    private const array BRANDS = [
        ['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego', 'description' => 'Building bricks and construction sets.', 'description_bn' => 'বিল্ডিং ব্রিক ও কনস্ট্রাকশন সেট।', 'country' => 'DK'],
        ['name' => 'Hot Wheels', 'name_bn' => 'হট হুইলস', 'slug' => 'hot-wheels', 'description' => 'Die-cast cars and race tracks.', 'description_bn' => 'ডাই-কাস্ট গাড়ি ও রেস ট্র্যাক।', 'country' => 'US'],
        ['name' => 'Barbie', 'name_bn' => 'বার্বি', 'slug' => 'barbie', 'description' => 'Fashion dolls and playsets.', 'description_bn' => 'ফ্যাশন পুতুল ও প্লেসেট।', 'country' => 'US'],
        ['name' => 'Funskool', 'name_bn' => 'ফানস্কুল', 'slug' => 'funskool', 'description' => 'Board games, puzzles and preschool toys.', 'description_bn' => 'বোর্ড গেম, পাজল ও প্রি-স্কুল খেলনা।', 'country' => 'IN'],
        ['name' => 'Fisher-Price', 'name_bn' => 'ফিশার-প্রাইস', 'slug' => 'fisher-price', 'description' => 'Baby and toddler toys.', 'description_bn' => 'শিশু ও ছোটদের খেলনা।', 'country' => 'US'],
        ['name' => 'Play-Doh', 'name_bn' => 'প্লে-ডো', 'slug' => 'play-doh', 'description' => 'Modelling compound and creative sets.', 'description_bn' => 'মডেলিং কম্পাউন্ড ও সৃজনশীল সেট।', 'country' => 'US'],
    ];

    public function run(): void
    {
        foreach (self::BRANDS as $position => $brand) {
            Brand::query()->updateOrCreate(
                ['slug' => $brand['slug']],
                [...$brand, 'status' => BrandStatus::Active, 'position' => $position],
            );
        }
    }
}
