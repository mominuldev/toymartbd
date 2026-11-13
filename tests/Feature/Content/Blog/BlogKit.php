<?php

declare(strict_types=1);

namespace Tests\Feature\Content\Blog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Actions\RecordOpeningStock;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Shared helpers for the Phase 7 blog tests. */
final class BlogKit
{
    public static function token(): string
    {
        return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    }

    /** @param  array<string, mixed>  $attributes */
    public static function post(array $attributes = []): BlogPost
    {
        static $n = 0;
        $n++;

        return BlogPost::query()->create([
            'type' => PostType::Article,
            'slug' => 'post-'.$n,
            'title' => 'Post '.$n,
            'title_bn' => 'লেখা '.$n,
            'excerpt' => 'Excerpt '.$n,
            'excerpt_bn' => 'সারাংশ '.$n,
            'body' => '<p>Body '.$n.'</p>',
            'body_bn' => '<p>লেখার মূল অংশ '.$n.'</p>',
            'status' => PostStatus::Published,
            'published_at' => Carbon::now()->subDays(10)->addMinutes($n),
            ...$attributes,
        ]);
    }

    public static function category(string $slug, string $name = 'Category'): BlogCategory
    {
        return BlogCategory::query()->create(['name' => $name, 'name_bn' => 'বিভাগ', 'slug' => $slug]);
    }

    public static function tag(string $slug): BlogTag
    {
        return BlogTag::query()->create(['name' => ucfirst($slug), 'name_bn' => 'ট্যাগ', 'slug' => $slug]);
    }

    public static function author(string $slug = 'rina'): BlogAuthor
    {
        return BlogAuthor::query()->create(['name' => 'Rina Akter', 'name_bn' => 'রিনা আক্তার', 'slug' => $slug, 'bio' => 'Parent of two.']);
    }

    /** A live product with opening stock (through Inventory, so the stock invariant holds). */
    public static function stockedProduct(string $slug, int $onHand = 5, string $price = '650.00'): Product
    {
        $product = Product::factory()->priced($price)->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        if ($onHand > 0) {
            app(RecordOpeningStock::class)->handle($product, [(int) $product->variants()->value('id') => $onHand]);
        }

        return $product;
    }

    public static function assertStockInvariant(): void
    {
        expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0]);
    }
}
