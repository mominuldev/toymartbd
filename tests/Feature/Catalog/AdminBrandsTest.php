<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Admin brands CRUD (P3-ADM-04): /api/v1/admin/brands.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/** @return list<string> */
function problemFields(Illuminate\Testing\TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}

it('creates a brand and returns the Brand shape', function (): void {
    $response = $this->postJson('/api/v1/admin/brands', [
        'name' => 'LEGO',
        'name_bn' => 'লেগো',
        'description' => 'Bricks.',
        'description_bn' => 'ব্রিক।',
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'LEGO')
        ->assertJsonPath('name_bn', 'লেগো')
        ->assertJsonPath('slug', 'lego')
        ->assertJsonPath('description_bn', 'ব্রিক।')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('logo_url', null);

    expect(array_keys($response->json()))->toBe([
        'id', 'name', 'name_bn', 'slug', 'description', 'description_bn', 'is_active', 'logo_url', 'created_at', 'updated_at',
    ])->and($response->json('id'))->toBeInt()
        ->and($response->json('created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    expect(Brand::query()->where('slug', 'lego')->value('status'))->toBe(BrandStatus::Active);
});

it('accepts an explicit slug and is_active false', function (): void {
    $this->postJson('/api/v1/admin/brands', ['name' => 'Hot Wheels', 'slug' => 'hw-cars', 'is_active' => false])
        ->assertCreated()
        ->assertJsonPath('slug', 'hw-cars')
        ->assertJsonPath('is_active', false);
});

it('generates an ASCII slug from a Bangla-only name', function (): void {
    $slug = $this->postJson('/api/v1/admin/brands', ['name' => 'খেলনা ঘর'])
        ->assertCreated()
        ->json('slug');

    expect($slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
});

it('makes a generated slug unique', function (): void {
    Brand::factory()->create(['slug' => 'lego']);

    $this->postJson('/api/v1/admin/brands', ['name' => 'Lego'])->assertCreated()->assertJsonPath('slug', 'lego-2');
});

it('rejects invalid input with a validation-error problem', function (array $body, string $field): void {
    Brand::factory()->create(['slug' => 'taken']);

    $response = $this->postJson('/api/v1/admin/brands', $body)->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');

    expect(problemFields($response))->toContain($field);
})->with([
    'missing name' => [['name_bn' => 'লেগো'], 'name'],
    'name too long' => [['name' => str_repeat('a', 121)], 'name'],
    'uppercase slug' => [['name' => 'LEGO', 'slug' => 'LEGO'], 'slug'],
    'Bangla slug' => [['name' => 'LEGO', 'slug' => 'লেগো'], 'slug'],
    'slug with spaces' => [['name' => 'LEGO', 'slug' => 'lego city'], 'slug'],
    'duplicate slug' => [['name' => 'Other', 'slug' => 'taken'], 'slug'],
    'slug too long' => [['name' => 'LEGO', 'slug' => str_repeat('a', 95)], 'slug'],
    'non-boolean is_active' => [['name' => 'LEGO', 'is_active' => 'maybe'], 'is_active'],
    'name with no sluggable characters' => [['name' => '!!!'], 'slug'],
]);

it('shows a brand, archived ones included', function (): void {
    $brand = Brand::factory()->archived()->create(['name' => 'Barbie']);

    $this->getJson("/api/v1/admin/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('id', $brand->id)
        ->assertJsonPath('is_active', false);
});

it('returns a not-found problem for an unknown brand', function (): void {
    $this->getJson('/api/v1/admin/brands/999999')
        ->assertNotFound()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');
});

it('updates only the fields sent and keeps the slug on rename', function (): void {
    $brand = Brand::factory()->create(['name' => 'Lego', 'slug' => 'lego', 'name_bn' => 'লেগো']);

    $this->patchJson("/api/v1/admin/brands/{$brand->id}", ['name' => 'LEGO Group', 'description' => null])
        ->assertOk()
        ->assertJsonPath('name', 'LEGO Group')
        ->assertJsonPath('slug', 'lego')
        ->assertJsonPath('name_bn', 'লেগো')
        ->assertJsonPath('description', null);
});

it('changes the slug when one is given and allows keeping its own slug', function (): void {
    $brand = Brand::factory()->create(['slug' => 'lego']);

    $this->patchJson("/api/v1/admin/brands/{$brand->id}", ['slug' => 'lego'])->assertOk();
    $this->patchJson("/api/v1/admin/brands/{$brand->id}", ['slug' => 'lego-group'])->assertOk()->assertJsonPath('slug', 'lego-group');
});

it('rejects a PATCH to another brand\'s slug or an empty name', function (): void {
    Brand::factory()->create(['slug' => 'barbie']);
    $brand = Brand::factory()->create(['slug' => 'lego']);

    expect(problemFields($this->patchJson("/api/v1/admin/brands/{$brand->id}", ['slug' => 'barbie'])->assertUnprocessable()))->toBe(['slug'])
        ->and(problemFields($this->patchJson("/api/v1/admin/brands/{$brand->id}", ['name' => ''])->assertUnprocessable()))->toBe(['name']);
});

it('archives on DELETE, idempotently, and restores with is_active', function (): void {
    $brand = Brand::factory()->create(['name' => 'Funskool']);

    $this->deleteJson("/api/v1/admin/brands/{$brand->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/brands/{$brand->id}")->assertNoContent();

    expect(Brand::query()->find($brand->id)?->status)->toBe(BrandStatus::Archived);

    $this->getJson('/api/v1/admin/brands?filter[status]=active')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/brands?filter[status]=archived')->assertOk()->assertJsonPath('data.0.id', $brand->id);

    $this->patchJson("/api/v1/admin/brands/{$brand->id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
});

it('lists brands sorted by name by default', function (): void {
    Brand::factory()->create(['name' => 'Play-Doh']);
    Brand::factory()->create(['name' => 'Barbie']);
    Brand::factory()->create(['name' => 'LEGO']);

    $this->getJson('/api/v1/admin/brands')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('data.*.name', ['Barbie', 'LEGO', 'Play-Doh']);

    $this->getJson('/api/v1/admin/brands?sort=-name')->assertOk()->assertJsonPath('data.*.name', ['Play-Doh', 'LEGO', 'Barbie']);
});

it('sorts by created_at both ways', function (): void {
    $old = Brand::factory()->create(['name' => 'B', 'created_at' => now()->subDay()]);
    $new = Brand::factory()->create(['name' => 'A', 'created_at' => now()]);

    $this->getJson('/api/v1/admin/brands?sort=created_at')->assertJsonPath('data.*.id', [$old->id, $new->id]);
    $this->getJson('/api/v1/admin/brands?sort=-created_at')->assertJsonPath('data.*.id', [$new->id, $old->id]);
});

it('searches name, name_bn and slug', function (): void {
    Brand::factory()->create(['name' => 'Hot Wheels', 'name_bn' => 'হট হুইলস', 'slug' => 'hot-wheels']);
    Brand::factory()->create(['name' => 'Barbie', 'name_bn' => 'বার্বি', 'slug' => 'barbie']);

    $this->getJson('/api/v1/admin/brands?filter[search]=wheel')->assertJsonPath('data.*.slug', ['hot-wheels']);
    $this->getJson('/api/v1/admin/brands?filter[search]='.urlencode('বার্বি'))->assertJsonPath('data.*.slug', ['barbie']);
    $this->getJson('/api/v1/admin/brands?filter[search]=hot-w')->assertJsonPath('data.*.slug', ['hot-wheels']);
    $this->getJson('/api/v1/admin/brands?filter[search]=%25')->assertJsonCount(0, 'data');
});

it('rejects an unlisted filter or sort with a bad-request problem', function (string $query): void {
    $this->getJson('/api/v1/admin/brands?'.$query)
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unknown filter' => ['filter[country]=DK'],
    'unknown sort' => ['sort=position'],
    'unknown status value' => ['filter[status]=deleted'],
]);

it('cursor-paginates 25 per page', function (): void {
    foreach (range(1, 30) as $i) {
        Brand::factory()->create(['name' => sprintf('Brand %02d', $i)]);
    }

    $first = $this->getJson('/api/v1/admin/brands')->assertOk()->assertJsonCount(25, 'data');
    $cursor = $first->json('meta.next_cursor');

    expect($cursor)->toBeString()
        ->and($first->json('meta.per_page'))->toBe(25)
        ->and($first->json('data.0.name'))->toBe('Brand 01');

    $second = $this->getJson('/api/v1/admin/brands?cursor='.$cursor)->assertOk()->assertJsonCount(5, 'data');

    expect($second->json('data.0.name'))->toBe('Brand 26')
        ->and($second->json('meta.next_cursor'))->toBeNull();
});
