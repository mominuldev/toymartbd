<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Demo category tree for local development and E2E — local/testing only, called
 * from DatabaseSeeder behind the environment check. The eight top-level categories
 * and their MegaMenu children are the approved design's (docs/_design/03-storefront-spec.md
 * PART C, C1.4), so the storefront and admin look like the Figma frames. Bangla names
 * on every node (DATABASE-DESIGN §7). Idempotent on slug; existing nodes are left
 * where staff moved them.
 */
class CategoryDemoSeeder extends Seeder
{
    /**
     * @var list<array{name: string, name_bn: string, slug: string, icon: string, children: list<array{name: string, name_bn: string, slug: string}>}>
     */
    private const array TREE = [
        ['name' => 'Building Blocks', 'name_bn' => 'বিল্ডিং ব্লক', 'slug' => 'building-blocks', 'icon' => 'cube', 'children' => [
            ['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego-sets'],
            ['name' => 'DUPLO', 'name_bn' => 'ডুপ্লো', 'slug' => 'duplo'],
            ['name' => 'Wooden Blocks', 'name_bn' => 'কাঠের ব্লক', 'slug' => 'wooden-blocks'],
            ['name' => 'Magnetic Blocks', 'name_bn' => 'ম্যাগনেটিক', 'slug' => 'magnetic-blocks'],
            ['name' => 'Mega Blocks', 'name_bn' => 'মেগা ব্লক', 'slug' => 'mega-blocks'],
        ]],
        ['name' => 'Cars & Remote Control', 'name_bn' => 'গাড়ি ও রিমোট', 'slug' => 'cars-rc', 'icon' => 'car', 'children' => [
            ['name' => 'Die-cast Cars', 'name_bn' => 'ডাই-কাস্ট গাড়ি', 'slug' => 'die-cast-cars'],
            ['name' => 'Remote Control', 'name_bn' => 'রিমোট কন্ট্রোল', 'slug' => 'remote-control'],
            ['name' => 'Trucks & Construction', 'name_bn' => 'ট্রাক ও কনস্ট্রাকশন', 'slug' => 'trucks-construction'],
            ['name' => 'Train Sets', 'name_bn' => 'ট্রেন সেট', 'slug' => 'train-sets'],
            ['name' => 'Track Sets', 'name_bn' => 'ট্র্যাক সেট', 'slug' => 'track-sets'],
        ]],
        ['name' => 'Puzzles & Games', 'name_bn' => 'পাজল ও গেম', 'slug' => 'puzzles-games', 'icon' => 'puzzle-piece', 'children' => [
            ['name' => 'Jigsaw Puzzles', 'name_bn' => 'জিগস পাজল', 'slug' => 'jigsaw-puzzles'],
            ['name' => 'Wooden Puzzles', 'name_bn' => 'কাঠের পাজল', 'slug' => 'wooden-puzzles'],
            ['name' => 'Board Games', 'name_bn' => 'বোর্ড গেম', 'slug' => 'board-games'],
            ['name' => 'Cubes & Brain Teasers', 'name_bn' => 'কিউব ও ব্রেইন টিজার', 'slug' => 'cubes-brain-teasers'],
        ]],
        ['name' => 'Dolls & Soft Toys', 'name_bn' => 'পুতুল ও সফট টয়', 'slug' => 'dolls-soft-toys', 'icon' => 'smiley', 'children' => [
            ['name' => 'Fashion Dolls', 'name_bn' => 'ফ্যাশন ডল', 'slug' => 'fashion-dolls'],
            ['name' => 'Baby Dolls', 'name_bn' => 'বেবি ডল', 'slug' => 'baby-dolls'],
            ['name' => 'Soft Toys', 'name_bn' => 'সফট টয়', 'slug' => 'soft-toys'],
            ['name' => 'Role Play Sets', 'name_bn' => 'রোল প্লে সেট', 'slug' => 'role-play-sets'],
        ]],
        ['name' => 'Baby & Toddler', 'name_bn' => 'শিশু ও বেবি', 'slug' => 'baby-toddler', 'icon' => 'baby', 'children' => [
            ['name' => 'Activity Gyms', 'name_bn' => 'অ্যাক্টিভিটি জিম', 'slug' => 'activity-gyms'],
            ['name' => 'Rattles & Teethers', 'name_bn' => 'র‍্যাটেল ও টিদার', 'slug' => 'rattles-teethers'],
            ['name' => 'Push & Pull', 'name_bn' => 'পুশ ও পুল', 'slug' => 'push-pull'],
            ['name' => 'Musical Toys', 'name_bn' => 'মিউজিক্যাল', 'slug' => 'musical-toys'],
        ]],
        ['name' => 'Outdoor & Ride-On', 'name_bn' => 'আউটডোর ও রাইড-অন', 'slug' => 'outdoor-ride-on', 'icon' => 'bicycle', 'children' => [
            ['name' => 'Slides & Swings', 'name_bn' => 'স্লাইড ও সুইং', 'slug' => 'slides-swings'],
            ['name' => 'Bikes & Tricycles', 'name_bn' => 'সাইকেল ও ট্রাইসাইকেল', 'slug' => 'bikes-tricycles'],
            ['name' => 'Pools & Water Play', 'name_bn' => 'পুল ও ওয়াটার', 'slug' => 'pools-water-play'],
            ['name' => 'Balls & Sports', 'name_bn' => 'বল ও স্পোর্টস', 'slug' => 'balls-sports'],
        ]],
        ['name' => 'Art & Craft', 'name_bn' => 'আর্ট ও ক্রাফট', 'slug' => 'art-craft', 'icon' => 'palette', 'children' => [
            ['name' => 'Clay & Dough', 'name_bn' => 'ক্লে ও ডো', 'slug' => 'clay-dough'],
            ['name' => 'Paints & Markers', 'name_bn' => 'রং ও মার্কার', 'slug' => 'paints-markers'],
            ['name' => 'Craft Kits', 'name_bn' => 'ক্রাফট কিট', 'slug' => 'craft-kits'],
            ['name' => 'Stickers & Stamps', 'name_bn' => 'স্টিকার ও স্ট্যাম্প', 'slug' => 'stickers-stamps'],
        ]],
        ['name' => 'Science & Learning', 'name_bn' => 'সায়েন্স ও লার্নিং', 'slug' => 'science-learning', 'icon' => 'books', 'children' => [
            ['name' => 'Experiment Kits', 'name_bn' => 'এক্সপেরিমেন্ট কিট', 'slug' => 'experiment-kits'],
            ['name' => 'Microscopes & Telescopes', 'name_bn' => 'মাইক্রোস্কোপ ও টেলিস্কোপ', 'slug' => 'microscopes-telescopes'],
            ['name' => 'STEM Building', 'name_bn' => 'STEM বিল্ডিং', 'slug' => 'stem-building'],
            ['name' => 'Flash Cards', 'name_bn' => 'ফ্ল্যাশ কার্ড', 'slug' => 'flash-cards'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::TREE as $root) {
            $parent = $this->plant($root, null);

            foreach ($root['children'] as $child) {
                $this->plant($child, $parent);
            }
        }
    }

    /**
     * @param  array{name: string, name_bn: string, slug: string, icon?: string}  $node
     */
    private function plant(array $node, ?Category $parent): Category
    {
        $category = Category::query()->where('slug', $node['slug'])->first();

        if ($category !== null) {
            return $category;
        }

        $category = new Category([
            'name' => $node['name'],
            'name_bn' => $node['name_bn'],
            'slug' => $node['slug'],
            'icon' => $node['icon'] ?? null,
            'description' => $node['name'].' for every age.',
            'description_bn' => $node['name_bn'].' — সব বয়সের জন্য।',
        ]);

        $parent !== null ? $category->appendToNode($parent)->save() : $category->saveAsRoot();

        return $category;
    }
}
