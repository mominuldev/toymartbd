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
 * Create a product with its variant matrix (P4-CAT-03): a simple product gets exactly
 * one default variant; a variable one gets a variant per combination of its selected
 * option values. Owns its transaction (rule 3); ProductChanged fires after commit.
 */
final readonly class CreateProduct
{
    public function __construct(private ProductWriter $writer) {}

    /** @throws ValidationException */
    public function handle(ProductData $data, ?ProductWriteContext $context = null): Product
    {
        try {
            return DB::transaction(function () use ($data, $context): Product {
                $product = new Product;
                $this->writer->write($product, $data, $context ?? new ProductWriteContext, isNew: true);

                ProductChanged::dispatch($product->id, $product->slug);

                return $product;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::uniqueViolation($e);
        }
    }

    /** Lost a race with a concurrent write of the same slug, SKU or barcode. */
    public static function uniqueViolation(UniqueConstraintViolationException $e): ValidationException
    {
        $message = $e->getMessage();

        $field = match (true) {
            str_contains($message, 'barcode') => 'variants',
            str_contains($message, 'sku') => 'variants',
            default => 'slug',
        };

        return ValidationException::withMessages([$field => $field === 'slug'
            ? __('validation.unique', ['attribute' => 'slug'])
            : 'A SKU or barcode in this matrix is already in use.']);
    }
}
