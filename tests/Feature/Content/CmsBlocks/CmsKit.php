<?php

declare(strict_types=1);

namespace Tests\Feature\Content\CmsBlocks;

use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Actions\RecordOpeningStock;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Shared helpers for the homepage-builder (CMS blocks) tests. */
final class CmsKit
{
    public const string ADMIN = '/api/v1/admin/content/home';

    public const string STOREFRONT = '/api/v1/storefront/content/home';

    public static function actAsEditor(Role $role = Role::ContentEditor): User
    {
        test()->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->withRole($role)->create()->refresh();
        test()->actingAs($user, 'web');

        return $user;
    }

    public static function storefrontToken(): string
    {
        return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    }

    /** @param  array<string, string>  $query */
    public static function home(string $locale = 'en', array $query = []): TestResponse
    {
        return test()->withToken(self::storefrontToken())->getJson(self::STOREFRONT.'?'.http_build_query(['locale' => $locale, ...$query]));
    }

    /** @param  array<string, string>  $query */
    public static function preview(?string $token, string $locale = 'en', array $query = []): TestResponse
    {
        $headers = $token === null ? [] : ['X-Preview-Token' => $token];

        return test()->withToken(self::storefrontToken())->getJson(self::STOREFRONT.'/preview?'.http_build_query(['locale' => $locale, ...$query]), $headers);
    }

    /**
     * A draft block written straight to the table (bypassing the API), for storefront tests.
     *
     * @param  array<string, mixed>  $content  merged over the type's defaults
     * @param  array<string, mixed>  $attributes
     */
    public static function block(string $type, array $content = [], array $attributes = []): CmsBlock
    {
        $enum = \App\Domain\Content\Blocks\Enums\BlockType::from($type);
        $schema = $enum->schema();
        $position = (int) CmsBlock::query()->where('placement', 'home')->max('position') + 1;

        return CmsBlock::query()->create([
            'uuid' => (string) Str::uuid(),
            'placement' => BlockPlacement::Home,
            'type' => $enum,
            'content' => $schema->normalize([...$schema->defaults(), ...$content]),
            'position' => CmsBlock::query()->where('placement', 'home')->exists() ? $position : 0,
            ...$attributes,
        ]);
    }

    /** A listable product with opening stock through Inventory, so the stock invariant holds. */
    public static function product(string $slug, int $onHand = 5, array $attributes = []): Product
    {
        $product = Product::factory()->priced('650.00')->create(['slug' => $slug, 'name' => ucfirst($slug), 'name_bn' => 'বাংলা '.$slug, ...$attributes]);
        if ($onHand > 0) {
            app(RecordOpeningStock::class)->handle($product, [(int) $product->variants()->value('id') => $onHand]);
        }

        return $product;
    }

    public static function assertStockInvariant(): void
    {
        expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0]);
    }

    /**
     * @return list<string>
     */
    public static function types(TestResponse $response): array
    {
        return array_column($response->json('blocks'), 'type');
    }
}
