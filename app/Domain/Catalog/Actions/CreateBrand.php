<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\BrandData;
use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Services\BrandSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Create a brand. Owns its transaction (rule 3); BrandChanged fires after commit. */
final readonly class CreateBrand
{
    public function __construct(private BrandSlugs $slugs) {}

    public function handle(BrandData $data): Brand
    {
        try {
            return DB::transaction(function () use ($data): Brand {
                $brand = Brand::query()->create([
                    'name' => $data->name,
                    'name_bn' => $data->nameBn,
                    'slug' => $data->slug ?? $this->slugs->generate($data->name, $data->nameBn),
                    'description' => $data->description,
                    'description_bn' => $data->descriptionBn,
                    'status' => BrandStatus::fromActive($data->isActive),
                ]);

                BrandChanged::dispatch($brand->id, $brand->slug);

                return $brand;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent write of the same slug.
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
