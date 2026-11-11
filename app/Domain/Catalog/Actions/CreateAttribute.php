<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\AttributeData;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Services\AttributeSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Create a product attribute, placed after the existing ones. */
final readonly class CreateAttribute
{
    public function __construct(private AttributeSlugs $slugs) {}

    public function handle(AttributeData $data): ProductAttribute
    {
        try {
            return DB::transaction(function () use ($data): ProductAttribute {
                $max = ProductAttribute::query()->max('position');

                return ProductAttribute::query()->create([
                    'name' => $data->name,
                    'name_bn' => $data->nameBn,
                    'slug' => $data->slug ?? $this->slugs->forAttribute($data->name, $data->nameBn),
                    'type' => $data->type,
                    'is_variant_defining' => $data->isVariantDefining,
                    'is_filterable' => $data->isFilterable,
                    'position' => is_numeric($max) ? (int) $max + 1 : 0,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
