<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * Demo brands for local development and E2E — the 16 brands of the approved design's
 * catalogue (docs/_design/03-storefront-spec.md PART C, C1). DATABASE-DESIGN §7: Bangla
 * names in every seed. Local/testing only — called from DatabaseSeeder behind an
 * environment check. Idempotent on slug.
 */
class BrandDemoSeeder extends Seeder
{
    /** @var list<array{name: string, name_bn: string, slug: string, description: string, description_bn: string, country: string}> */
    private const array BRANDS = [
        ['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego', 'description' => 'Building bricks and construction sets.', 'description_bn' => 'বিল্ডিং ব্রিক ও কনস্ট্রাকশন সেট।', 'country' => 'DK'],
        ['name' => 'Fisher-Price', 'name_bn' => 'ফিশার-প্রাইস', 'slug' => 'fisher-price', 'description' => 'Baby and toddler toys.', 'description_bn' => 'শিশু ও ছোটদের খেলনা।', 'country' => 'US'],
        ['name' => 'Hot Wheels', 'name_bn' => 'হট হুইলস', 'slug' => 'hot-wheels', 'description' => 'Die-cast cars and race tracks.', 'description_bn' => 'ডাই-কাস্ট গাড়ি ও রেস ট্র্যাক।', 'country' => 'US'],
        ['name' => 'Hape', 'name_bn' => 'হেপ', 'slug' => 'hape', 'description' => 'Wooden toys for babies and toddlers.', 'description_bn' => 'শিশু ও ছোটদের কাঠের খেলনা।', 'country' => 'DE'],
        ['name' => 'Mideer', 'name_bn' => 'মিডির', 'slug' => 'mideer', 'description' => 'Wooden puzzles and early-learning toys.', 'description_bn' => 'কাঠের পাজল ও প্রাথমিক শিক্ষার খেলনা।', 'country' => 'CN'],
        ['name' => 'Huanger', 'name_bn' => 'হুয়াংগার', 'slug' => 'huanger', 'description' => 'Activity gyms and baby play mats.', 'description_bn' => 'অ্যাক্টিভিটি জিম ও বেবি প্লে ম্যাট।', 'country' => 'CN'],
        ['name' => 'Bruder', 'name_bn' => 'ব্রুডার', 'slug' => 'bruder', 'description' => 'Large-scale trucks and construction vehicles.', 'description_bn' => 'বড় স্কেলের ট্রাক ও কনস্ট্রাকশন গাড়ি।', 'country' => 'DE'],
        ['name' => "Rubik's", 'name_bn' => 'রুবিক্স', 'slug' => 'rubiks', 'description' => 'The original puzzle cube.', 'description_bn' => 'আসল পাজল কিউব।', 'country' => 'GB'],
        ['name' => 'Play-Doh', 'name_bn' => 'প্লে-ডো', 'slug' => 'play-doh', 'description' => 'Modelling compound and creative sets.', 'description_bn' => 'মডেলিং কম্পাউন্ড ও সৃজনশীল সেট।', 'country' => 'US'],
        ['name' => 'Barbie', 'name_bn' => 'বার্বি', 'slug' => 'barbie', 'description' => 'Fashion dolls and playsets.', 'description_bn' => 'ফ্যাশন পুতুল ও প্লেসেট।', 'country' => 'US'],
        ['name' => 'Clementoni', 'name_bn' => 'ক্লেমেন্টনি', 'slug' => 'clementoni', 'description' => 'Science kits and puzzles.', 'description_bn' => 'সায়েন্স কিট ও পাজল।', 'country' => 'IT'],
        ['name' => 'Little Tikes', 'name_bn' => 'লিটল টাইকস', 'slug' => 'little-tikes', 'description' => 'Slides, ride-ons and outdoor play.', 'description_bn' => 'স্লাইড, রাইড-অন ও আউটডোর খেলা।', 'country' => 'US'],
        ['name' => 'Intex', 'name_bn' => 'ইনটেক্স', 'slug' => 'intex', 'description' => 'Inflatable pools and water play.', 'description_bn' => 'ইনফ্ল্যাটেবল পুল ও পানির খেলা।', 'country' => 'US'],
        ['name' => 'Crayola', 'name_bn' => 'ক্রেয়োলা', 'slug' => 'crayola', 'description' => 'Crayons, markers and art supplies.', 'description_bn' => 'ক্রেয়ন, মার্কার ও আঁকার সরঞ্জাম।', 'country' => 'US'],
        ['name' => 'Winfun', 'name_bn' => 'উইনফান', 'slug' => 'winfun', 'description' => 'Baby walkers and infant toys.', 'description_bn' => 'বেবি ওয়াকার ও শিশুদের খেলনা।', 'country' => 'HK'],
        ['name' => 'Melissa & Doug', 'name_bn' => 'মেলিসা অ্যান্ড ডাগ', 'slug' => 'melissa-doug', 'description' => 'Wooden pretend-play sets.', 'description_bn' => 'কাঠের রোল-প্লে সেট।', 'country' => 'US'],
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
