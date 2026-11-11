<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Storefront brands: bearer token with `storefront:read`, active brands only.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function storefrontToken(array $abilities = ['storefront:read']): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', $abilities)->plainTextToken;
}

it('lists active brands by name without ids or timestamps', function (): void {
    Brand::factory()->create(['name' => 'Play-Doh', 'name_bn' => 'প্লে-ডো', 'slug' => 'play-doh']);
    Brand::factory()->create(['name' => 'Barbie', 'slug' => 'barbie']);
    Brand::factory()->archived()->create(['name' => 'Archived Co', 'slug' => 'archived-co']);

    $response = $this->withToken(storefrontToken())->getJson('/api/v1/storefront/brands')->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and($response->json('data.*.slug'))->toBe(['barbie', 'play-doh'])
        ->and(array_keys($response->json('data.1')))->toBe(['name', 'name_bn', 'slug', 'description', 'description_bn', 'logo_url'])
        ->and($response->json('data.1.name_bn'))->toBe('প্লে-ডো');
});

it('shows one active brand by slug', function (): void {
    Brand::factory()->create(['name' => 'LEGO', 'slug' => 'lego']);

    $this->withToken(storefrontToken())->getJson('/api/v1/storefront/brands/lego')
        ->assertOk()
        ->assertExactJson([
            'name' => 'LEGO',
            'name_bn' => Brand::query()->where('slug', 'lego')->value('name_bn'),
            'slug' => 'lego',
            'description' => Brand::query()->where('slug', 'lego')->value('description'),
            'description_bn' => Brand::query()->where('slug', 'lego')->value('description_bn'),
            'logo_url' => null,
        ]);
});

it('returns a not-found problem for a missing or archived brand', function (string $slug): void {
    Brand::factory()->archived()->create(['slug' => 'gone']);

    $this->withToken(storefrontToken())->getJson("/api/v1/storefront/brands/{$slug}")
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');
})->with(['gone', 'never-existed']);

it('hides a brand from the storefront once archived through the admin API', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $brand = Brand::factory()->create(['slug' => 'funskool']);
    $token = storefrontToken();

    $this->withToken($token)->getJson('/api/v1/storefront/brands/funskool')->assertOk();

    auth()->forgetGuards();
    $this->withoutToken()->actingAs(User::factory()->withRole(App\Models\Enums\Role::Manager)->create()->refresh(), 'web')
        ->deleteJson("/api/v1/admin/brands/{$brand->id}")
        ->assertNoContent();
    auth()->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/storefront/brands/funskool')->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/storefront/brands')->assertOk()->assertJsonCount(0, 'data');
});

it('requires a bearer token', function (string $uri): void {
    Brand::factory()->create(['slug' => 'lego']);

    $this->getJson($uri)
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');

    auth()->forgetGuards();
    $this->withToken('1|not-a-real-token')->getJson($uri)->assertUnauthorized();
})->with(['/api/v1/storefront/brands', '/api/v1/storefront/brands/lego']);

it('refuses a token without storefront:read', function (string $uri): void {
    Brand::factory()->create(['slug' => 'lego']);

    $this->withToken(storefrontToken(['orders.view']))->getJson($uri)
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/forbidden');
})->with(['/api/v1/storefront/brands', '/api/v1/storefront/brands/lego']);

it('keeps the storefront ping public', function (): void {
    $this->getJson('/api/v1/storefront/ping')->assertOk();
});
