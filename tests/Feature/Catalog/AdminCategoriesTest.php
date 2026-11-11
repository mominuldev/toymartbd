<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * Admin categories (P4-CAT-08): /api/v1/admin/categories — nested set, archive-only.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/** @return list<string> */
function categoryProblemFields(TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}

/** @return list<string> slugs of the children of `$parent` (null = roots), in tree order */
function childSlugs(?Category $parent): array
{
    $query = $parent === null ? Category::query()->whereNull('parent_id') : Category::query()->where('parent_id', $parent->id);

    return $query->defaultOrder()->pluck('slug')->all();
}

it('creates a root category and returns the Category shape', function (): void {
    $response = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Educational Toys',
        'name_bn' => 'শিক্ষামূলক খেলনা',
        'icon' => 'graduation-cap',
        'is_featured' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('slug', 'educational-toys')
        ->assertJsonPath('name_bn', 'শিক্ষামূলক খেলনা')
        ->assertJsonPath('parent_id', null)
        ->assertJsonPath('depth', 0)
        ->assertJsonPath('position', 0)
        ->assertJsonPath('icon', 'graduation-cap')
        ->assertJsonPath('is_featured', true)
        ->assertJsonPath('show_in_menu', true)
        ->assertJsonPath('is_active', true);

    expect(array_keys($response->json()))->toBe([
        'id', 'parent_id', 'name', 'name_bn', 'slug', 'description', 'description_bn', 'icon', 'image_url',
        'is_featured', 'show_in_menu', 'is_active', 'position', 'depth', 'created_at', 'updated_at',
    ]);
});

it('appends a child as the last sibling and generates ASCII slugs from Bangla', function (): void {
    $root = Category::factory()->create(['slug' => 'toys']);
    Category::factory()->childOf($root)->create(['slug' => 'first']);

    $response = $this->postJson('/api/v1/admin/categories', ['name' => 'পাজল', 'parent_id' => $root->id])->assertCreated();

    expect($response->json('slug'))->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
        ->and($response->json('depth'))->toBe(1)
        ->and($response->json('position'))->toBe(1)
        ->and(childSlugs($root))->toBe(['first', $response->json('slug')])
        ->and(Category::isBroken())->toBeFalse();
});

it('makes a generated slug unique and never generates a reserved one', function (): void {
    Category::factory()->create(['slug' => 'puzzles']);

    $this->postJson('/api/v1/admin/categories', ['name' => 'Puzzles'])->assertCreated()->assertJsonPath('slug', 'puzzles-2');
    $this->postJson('/api/v1/admin/categories', ['name' => 'Tree'])->assertCreated()->assertJsonPath('slug', 'tree-2');
});

it('rejects invalid input with a validation-error problem', function (array $body, string $field): void {
    Category::factory()->create(['slug' => 'taken']);

    $response = $this->postJson('/api/v1/admin/categories', $body)->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');

    expect(categoryProblemFields($response))->toContain($field);
})->with([
    'missing name' => [['name_bn' => 'পাজল'], 'name'],
    'uppercase slug' => [['name' => 'X', 'slug' => 'Puzzles'], 'slug'],
    'duplicate slug' => [['name' => 'X', 'slug' => 'taken'], 'slug'],
    'reserved slug' => [['name' => 'X', 'slug' => 'tree'], 'slug'],
    'slug too long for the tag limit' => [['name' => 'X', 'slug' => str_repeat('a', 92)], 'slug'],
    'unknown parent' => [['name' => 'X', 'parent_id' => 999999], 'parent_id'],
    'bad icon' => [['name' => 'X', 'icon' => 'Puzzle Piece'], 'icon'],
    'unsluggable name' => [['name' => '!!!'], 'slug'],
]);

it('refuses a fourth level with a 422', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->childOf($a)->create();
    $c = Category::factory()->childOf($b)->create();

    $response = $this->postJson('/api/v1/admin/categories', ['name' => 'Too deep', 'parent_id' => $c->id])->assertUnprocessable();

    expect(categoryProblemFields($response))->toBe(['parent_id']);
});

it('refuses an active child under an archived parent with a 409', function (): void {
    $parent = Category::factory()->archived()->create();

    $this->postJson('/api/v1/admin/categories', ['name' => 'Child', 'parent_id' => $parent->id])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict')
        ->assertJsonPath('detail', 'The parent category is archived. Restore or change the parent first.');

    $this->postJson('/api/v1/admin/categories', ['name' => 'Child', 'parent_id' => $parent->id, 'is_active' => false])->assertCreated();
});

it('shows a category with its depth, archived included', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->archived()->create();

    $this->getJson("/api/v1/admin/categories/{$child->id}")
        ->assertOk()
        ->assertJsonPath('parent_id', $root->id)
        ->assertJsonPath('depth', 1)
        ->assertJsonPath('is_active', false);

    $this->getJson('/api/v1/admin/categories/999999')->assertNotFound();
});

it('updates only the fields sent and keeps the slug on rename', function (): void {
    $category = Category::factory()->create(['name' => 'Puzzles', 'slug' => 'puzzles', 'name_bn' => 'পাজল']);

    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Jigsaws', 'show_in_menu' => false, 'icon' => null])
        ->assertOk()
        ->assertJsonPath('name', 'Jigsaws')
        ->assertJsonPath('slug', 'puzzles')
        ->assertJsonPath('name_bn', 'পাজল')
        ->assertJsonPath('show_in_menu', false);

    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['slug' => 'jigsaws'])->assertOk()->assertJsonPath('slug', 'jigsaws');
});

it('lists categories flat in tree order with depth, cursor-paginated', function (): void {
    $b = Category::factory()->create(['name' => 'B root', 'slug' => 'b']);
    Category::factory()->childOf($b)->create(['name' => 'A child', 'slug' => 'b-child']);
    Category::factory()->create(['name' => 'A root', 'slug' => 'a']);

    $this->getJson('/api/v1/admin/categories')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('data.*.slug', ['b', 'b-child', 'a'])
        ->assertJsonPath('data.*.depth', [0, 1, 0]);

    $this->getJson('/api/v1/admin/categories?sort=name')->assertJsonPath('data.*.slug', ['b-child', 'a', 'b']);
});

it('filters by search, status and parent', function (): void {
    $root = Category::factory()->create(['name' => 'Vehicles', 'name_bn' => 'গাড়ি', 'slug' => 'vehicles']);
    Category::factory()->childOf($root)->archived()->create(['name' => 'RC Cars', 'slug' => 'rc-cars']);

    $this->getJson('/api/v1/admin/categories?filter[search]='.urlencode('গাড়ি'))->assertJsonPath('data.*.slug', ['vehicles']);
    $this->getJson('/api/v1/admin/categories?filter[status]=archived')->assertJsonPath('data.*.slug', ['rc-cars']);
    $this->getJson('/api/v1/admin/categories?filter[parent_id]=root')->assertJsonPath('data.*.slug', ['vehicles']);
    $this->getJson("/api/v1/admin/categories?filter[parent_id]={$root->id}")->assertJsonPath('data.*.slug', ['rc-cars']);
});

it('rejects an unlisted filter, sort or value with a bad-request problem', function (string $query): void {
    $this->getJson('/api/v1/admin/categories?'.$query)
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unknown filter' => ['filter[icon]=car'],
    'unknown sort' => ['sort=_lft'],
    'unknown status' => ['filter[status]=deleted'],
    'bad parent' => ['filter[parent_id]=abc'],
    'filter on the tree' => ['tree=1&filter[status]=active'],
]);

it('returns the whole nested tree with ?tree=1, archived included', function (): void {
    $toys = Category::factory()->create(['slug' => 'toys']);
    $puzzles = Category::factory()->childOf($toys)->create(['slug' => 'puzzles']);
    Category::factory()->childOf($puzzles)->archived()->create(['slug' => 'jigsaw']);
    Category::factory()->create(['slug' => 'books']);

    $response = $this->getJson('/api/v1/admin/categories?tree=1')->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and($response->json('data.*.slug'))->toBe(['toys', 'books'])
        ->and($response->json('data.0.children.0.slug'))->toBe('puzzles')
        ->and($response->json('data.0.children.0.depth'))->toBe(1)
        ->and($response->json('data.0.children.0.children.0.slug'))->toBe('jigsaw')
        ->and($response->json('data.0.children.0.children.0.is_active'))->toBeFalse()
        ->and($response->json('data.1.children'))->toBe([]);
});

it('reorders among siblings', function (): void {
    $root = Category::factory()->create();
    $a = Category::factory()->childOf($root)->create(['slug' => 'a']);
    Category::factory()->childOf($root)->create(['slug' => 'b']);
    $c = Category::factory()->childOf($root)->create(['slug' => 'c']);

    $this->postJson("/api/v1/admin/categories/{$c->id}/move", ['parent_id' => $root->id, 'position' => 0])
        ->assertOk()->assertJsonPath('position', 0);
    expect(childSlugs($root))->toBe(['c', 'a', 'b']);

    $this->postJson("/api/v1/admin/categories/{$a->id}/move", ['parent_id' => $root->id, 'position' => 99])
        ->assertOk()->assertJsonPath('position', 2);
    expect(childSlugs($root))->toBe(['c', 'b', 'a'])
        ->and(Category::query()->where('parent_id', $root->id)->defaultOrder()->pluck('position')->all())->toBe([0, 1, 2])
        ->and(Category::isBroken())->toBeFalse();
});

it('reorders roots, including to the end', function (): void {
    $x = Category::factory()->create(['slug' => 'x']);
    Category::factory()->create(['slug' => 'y']);
    Category::factory()->create(['slug' => 'z']);

    $this->postJson("/api/v1/admin/categories/{$x->id}/move", ['parent_id' => null, 'position' => 2])->assertOk();

    expect(childSlugs(null))->toBe(['y', 'z', 'x']);
});

it('indents under the previous sibling and outdents back, moving the subtree', function (): void {
    $a = Category::factory()->create(['slug' => 'a']);
    $b = Category::factory()->create(['slug' => 'b']);
    Category::factory()->childOf($b)->create(['slug' => 'b-child']);

    $this->postJson("/api/v1/admin/categories/{$b->id}/move", ['parent_id' => $a->id, 'position' => 0])
        ->assertOk()->assertJsonPath('parent_id', $a->id)->assertJsonPath('depth', 1);

    expect(childSlugs(null))->toBe(['a'])
        ->and(childSlugs($a))->toBe(['b'])
        ->and(Category::query()->where('slug', 'b-child')->firstOrFail()->ancestors()->pluck('slug')->all())->toBe(['a', 'b']);

    $this->postJson("/api/v1/admin/categories/{$b->id}/move", ['parent_id' => null, 'position' => 1])
        ->assertOk()->assertJsonPath('parent_id', null)->assertJsonPath('position', 1);

    expect(childSlugs(null))->toBe(['a', 'b'])->and(Category::isBroken())->toBeFalse();
});

it('refuses to move a category under itself or a descendant with a 422', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->childOf($a)->create();

    foreach ([$a, $b] as $target) {
        $response = $this->postJson("/api/v1/admin/categories/{$a->id}/move", ['parent_id' => $target->id, 'position' => 0])
            ->assertUnprocessable();
        expect(categoryProblemFields($response))->toBe(['parent_id']);
    }

    expect($a->refresh()->parent_id)->toBeNull();
});

it('refuses a move that would nest deeper than three levels', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->childOf($a)->create();
    $other = Category::factory()->create();
    Category::factory()->childOf($other)->create();

    // $other has one level below it; under $b that would be depth 2 + 1 = 3.
    $this->postJson("/api/v1/admin/categories/{$other->id}/move", ['parent_id' => $b->id, 'position' => 0])->assertUnprocessable();
});

it('validates the move body', function (array $body): void {
    $category = Category::factory()->create();

    $this->postJson("/api/v1/admin/categories/{$category->id}/move", $body)->assertUnprocessable();
})->with([
    'missing parent_id' => [['position' => 0]],
    'missing position' => [['parent_id' => null]],
    'negative position' => [['parent_id' => null, 'position' => -1]],
    'unknown parent' => [['parent_id' => 999999, 'position' => 0]],
]);

it('refuses to move an active category under an archived one with a 409', function (): void {
    $archived = Category::factory()->archived()->create();
    $category = Category::factory()->create();

    $this->postJson("/api/v1/admin/categories/{$category->id}/move", ['parent_id' => $archived->id, 'position' => 0])->assertStatus(409);
});

it('archives on DELETE, idempotently, and refuses while active children remain', function (): void {
    $parent = Category::factory()->create();
    $child = Category::factory()->childOf($parent)->create();

    $this->deleteJson("/api/v1/admin/categories/{$parent->id}")
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
    $this->patchJson("/api/v1/admin/categories/{$parent->id}", ['is_active' => false])->assertStatus(409);

    $this->deleteJson("/api/v1/admin/categories/{$child->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/categories/{$parent->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/categories/{$parent->id}")->assertNoContent();

    expect($parent->refresh()->status)->toBe(CategoryStatus::Archived)
        ->and(Category::withTrashed()->count())->toBe(2)
        ->and(Category::onlyTrashed()->count())->toBe(0);

    // Restoring the child first is refused: its parent is archived.
    $this->patchJson("/api/v1/admin/categories/{$child->id}", ['is_active' => true])->assertStatus(409);
    $this->patchJson("/api/v1/admin/categories/{$parent->id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
    $this->patchJson("/api/v1/admin/categories/{$child->id}", ['is_active' => true])->assertOk();
});
