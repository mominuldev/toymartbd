<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * Attribute edits reach the storefront (docs/api/revalidation.md): product pages name their
 * variant axes and options, so a rename or reorder purges `products`, which every product
 * page carries. Create and delete touch no variant: no purge.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    Queue::fake();
});

$productsPurge = static fn (RevalidateStorefrontTags $job): bool => $job->tags === ['products'] && $job->afterCommit === true;

it('purges `products` when an attribute is edited', function () use ($productsPurge): void {
    $attribute = ProductAttribute::factory()->create();

    $this->patchJson("/api/v1/admin/attributes/{$attribute->id}", ['name' => 'Shade', 'name_bn' => 'শেড'])->assertOk();

    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, $productsPurge);
    Queue::assertPushed(RevalidateStorefrontTags::class, 1);
});

it('purges `products` when a value is renamed or the values are reordered', function () use ($productsPurge): void {
    $attribute = ProductAttribute::factory()->create();
    $a = ProductAttributeValue::factory()->for($attribute, 'attribute')->create(['position' => 0]);
    $b = ProductAttributeValue::factory()->for($attribute, 'attribute')->create(['position' => 1]);

    $this->patchJson("/api/v1/admin/attributes/{$attribute->id}/values/{$a->id}", ['value' => 'Tiny', 'value_bn' => 'খুব ছোট'])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 1);

    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values/reorder", ['ids' => [$b->id, $a->id]])->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, $productsPurge);
});

it('sends nothing when an attribute or value is created or deleted, or a write is refused', function (): void {
    $attribute = ProductAttribute::factory()->create();
    $value = ProductAttributeValue::factory()->for($attribute, 'attribute')->create();
    $other = ProductAttribute::factory()->create();

    $this->postJson('/api/v1/admin/attributes', ['name' => 'Material', 'name_bn' => 'উপাদান'])->assertCreated();
    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values", ['value' => 'Wood', 'value_bn' => 'কাঠ'])->assertCreated();
    $this->patchJson("/api/v1/admin/attributes/{$other->id}/values/{$value->id}", ['value' => 'Nope'])->assertNotFound();
    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values/reorder", ['ids' => []])->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/attributes/{$attribute->id}/values/{$value->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/attributes/{$other->id}")->assertNoContent();

    Queue::assertNotPushed(RevalidateStorefrontTags::class);
});
