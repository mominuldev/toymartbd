<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Jobs\DeleteComplianceCertificate;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/** Detach a product's BSTI certificate PDF (idempotent); the file is deleted after commit. */
final class RemoveComplianceCertificate
{
    public function handle(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $key = $product->bsti_certificate_path;

            if ($key === null) {
                return $product;
            }

            $product->forceFill(['bsti_certificate_path' => null, 'bsti_certificate_name' => null, 'bsti_certificate_size' => null])->save();

            DeleteComplianceCertificate::dispatch($key);
            ProductChanged::dispatch($product->id, $product->slug);

            return $product;
        });
    }
}
