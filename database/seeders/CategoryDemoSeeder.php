<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Demo category tree for local development and E2E — local/testing only, called
 * from DatabaseSeeder behind the environment check. Bangla names on every node
 * (DATABASE-DESIGN §7). Idempotent on slug; existing nodes are left where staff
 * moved them.
 */
class CategoryDemoSeeder extends Seeder
{
    /**
     * @var list<array{name: string, name_bn: string, slug: string, icon: string, children?: list<array{name: string, name_bn: string, slug: string, icon?: string, children?: list<array{name: string, name_bn: string, slug: string}>}>}>
     */
    private const array TREE = [
        ['name' => 'Educational Toys', 'name_bn' => 'শিক্ষামূলক খেলনা', 'slug' => 'educational-toys', 'icon' => 'graduation-cap', 'children' => [
            ['name' => 'Puzzles', 'name_bn' => 'পাজল', 'slug' => 'puzzles', 'children' => [
                ['name' => 'Jigsaw Puzzles', 'name_bn' => 'জিগস পাজল', 'slug' => 'jigsaw-puzzles'],
                ['name' => '3D Puzzles', 'name_bn' => 'থ্রিডি পাজল', 'slug' => '3d-puzzles'],
            ]],
            ['name' => 'STEM Kits', 'name_bn' => 'স্টেম কিট', 'slug' => 'stem-kits'],
        ]],
        ['name' => 'Baby & Toddler', 'name_bn' => 'শিশু ও ছোটদের', 'slug' => 'baby-toddler', 'icon' => 'baby', 'children' => [
            ['name' => 'Rattles & Teethers', 'name_bn' => 'ঝুনঝুনি ও টিদার', 'slug' => 'rattles-teethers'],
            ['name' => 'Stacking Toys', 'name_bn' => 'স্ট্যাকিং খেলনা', 'slug' => 'stacking-toys'],
        ]],
        ['name' => 'Building Blocks', 'name_bn' => 'বিল্ডিং ব্লক', 'slug' => 'building-blocks', 'icon' => 'cube'],
        ['name' => 'Dolls & Playsets', 'name_bn' => 'পুতুল ও প্লেসেট', 'slug' => 'dolls-playsets', 'icon' => 'person-simple'],
        ['name' => 'Vehicles', 'name_bn' => 'গাড়ি', 'slug' => 'vehicles', 'icon' => 'car', 'children' => [
            ['name' => 'Remote Control', 'name_bn' => 'রিমোট কন্ট্রোল', 'slug' => 'remote-control'],
            ['name' => 'Die-cast Cars', 'name_bn' => 'ডাই-কাস্ট গাড়ি', 'slug' => 'die-cast-cars'],
        ]],
        ['name' => 'Arts & Crafts', 'name_bn' => 'আর্ট ও ক্রাফট', 'slug' => 'arts-crafts', 'icon' => 'palette'],
    ];

    public function run(): void
    {
        foreach (self::TREE as $node) {
            $this->plant($node, null);
        }
    }

    /**
     * @param  array{name: string, name_bn: string, slug: string, icon?: string, children?: list<mixed>}  $node
     */
    private function plant(array $node, ?Category $parent): void
    {
        $category = Category::query()->where('slug', $node['slug'])->first();

        if ($category === null) {
            $category = new Category([
                'name' => $node['name'],
                'name_bn' => $node['name_bn'],
                'slug' => $node['slug'],
                'icon' => $node['icon'] ?? null,
                'description' => $node['name'].' for every age.',
                'description_bn' => $node['name_bn'].' — সব বয়সের জন্য।',
            ]);

            $parent !== null ? $category->appendToNode($parent)->save() : $category->saveAsRoot();
        }

        /** @var list<array{name: string, name_bn: string, slug: string, icon?: string, children?: list<mixed>}> $children */
        $children = $node['children'] ?? [];
        foreach ($children as $child) {
            $this->plant($child, $category);
        }
    }
}
