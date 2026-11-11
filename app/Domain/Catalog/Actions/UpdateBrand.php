<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit a brand. Only the keys present in `$changes` are written. The slug changes
 * only when one is given explicitly — renaming a brand never silently moves its URL.
 * `is_active: true` restores an archived brand.
 */
final class UpdateBrand
{
    /**
     * @param  array{name?: string, name_bn?: string|null, slug?: string, description?: string|null, description_bn?: string|null, is_active?: bool}  $changes
     */
    public function handle(Brand $brand, array $changes): Brand
    {
        try {
            return DB::transaction(function () use ($brand, $changes): Brand {
                $previousSlug = $brand->slug;

                $attributes = array_intersect_key($changes, array_flip(['name', 'name_bn', 'slug', 'description', 'description_bn']));

                if (array_key_exists('is_active', $changes)) {
                    $attributes['status'] = BrandStatus::fromActive($changes['is_active']);
                }

                $brand->fill($attributes);

                if (! $brand->isDirty()) {
                    return $brand;
                }

                $brand->save();

                BrandChanged::dispatch(
                    $brand->id,
                    $brand->slug,
                    $brand->slug !== $previousSlug ? $previousSlug : null,
                );

                return $brand;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
