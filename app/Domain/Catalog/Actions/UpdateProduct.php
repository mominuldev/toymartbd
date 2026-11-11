<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\ProductWriteContext;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replace a product's document, variant matrix included (P4-CAT-03). Existing
 * variants keep their ids, SKUs and prices unless a row changes them; removing an
 * option value archives the variants that used it; adding one creates the new
 * combinations. The slug changes only when one is given — a rename never moves the
 * URL. ProductChanged fires after commit, and only when something changed.
 */
final readonly class UpdateProduct
{
    public function __construct(private ProductWriter $writer) {}

    /** @throws ValidationException */
    public function handle(Product $product, ProductData $data, ?ProductWriteContext $context = null): Product
    {
        try {
            return DB::transaction(function () use ($product, $data, $context): Product {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                $previousSlug = $product->slug;

                if (! $this->writer->write($product, $data, $context ?? new ProductWriteContext, isNew: false)) {
                    return $product;
                }

                ProductChanged::dispatch(
                    $product->id,
                    $product->slug,
                    $product->slug !== $previousSlug ? $previousSlug : null,
                );

                return $product;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw CreateProduct::uniqueViolation($e);
        }
    }
}
