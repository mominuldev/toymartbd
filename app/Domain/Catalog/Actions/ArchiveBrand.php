<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Support\Facades\DB;

/**
 * Archive a brand: it leaves the storefront and the `active` admin filter but the
 * row stays, because products reference it. Idempotent — archiving an archived
 * brand changes nothing and fires nothing.
 */
final class ArchiveBrand
{
    public function handle(Brand $brand): Brand
    {
        return DB::transaction(function () use ($brand): Brand {
            $brand = Brand::query()->lockForUpdate()->findOrFail($brand->id);

            if ($brand->status === BrandStatus::Archived) {
                return $brand;
            }

            $brand->status = BrandStatus::Archived;
            $brand->save();

            BrandChanged::dispatch($brand->id, $brand->slug);

            return $brand;
        });
    }
}
