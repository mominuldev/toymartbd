<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;

/*
 * Category (nested set) and attribute models + factories (P4-CAT-01/09).
 */

it('builds a nested category tree through the factory', function (): void {
    $root = Category::factory()->create(['name' => 'Toys', 'name_bn' => 'খেলনা']);
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    expect($root->refresh()->status)->toBe(CategoryStatus::Active)
        ->and($child->refresh()->parent_id)->toBe($root->id)
        ->and($grandchild->refresh()->ancestors()->pluck('id')->all())->toBe([$root->id, $child->id])
        ->and(Category::query()->withDepth()->find($grandchild->id)?->getAttribute('depth'))->toBe(2)
        ->and(Category::isBroken())->toBeFalse();
});

it('creates attributes with ordered values', function (): void {
    $color = ProductAttribute::factory()->color()->create();
    ProductAttributeValue::factory()->for($color, 'attribute')->colored('#1E88E5')->create(['position' => 2, 'value' => 'Blue']);
    ProductAttributeValue::factory()->for($color, 'attribute')->colored()->create(['position' => 1, 'value' => 'Red']);

    expect($color->type)->toBe(AttributeType::Color)
        ->and($color->values()->pluck('value')->all())->toBe(['Red', 'Blue'])
        ->and(ProductAttributeValue::factory()->create()->attribute)->toBeInstanceOf(ProductAttribute::class);
});
