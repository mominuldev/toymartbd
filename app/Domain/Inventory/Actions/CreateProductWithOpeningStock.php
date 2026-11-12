<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\ProductWriteContext;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\VariantMatrix;
use App\Domain\Inventory\Services\OpeningStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `POST /admin/products` with opening stock: create the product (Catalog's own Action,
 * which nests as a savepoint) and record an `opening_balance` movement per variant
 * that was given a quantity — one transaction, so a product never exists with half its
 * opening stock. Inventory may depend on Catalog, never the reverse (deptrac), which is
 * why this Action lives here and not in CreateProduct.
 *
 * `$openingStock[i]` is the quantity for `$data->variants[i]`; variants are matched to
 * the created rows by their combination (sorted attribute value ids), the same identity
 * VariantMatrix uses. Later changes to stock are adjustments (Phase 5), never a second
 * opening balance.
 */
final readonly class CreateProductWithOpeningStock
{
    public function __construct(private CreateProduct $createProduct, private OpeningStock $openingStock) {}

    /**
     * @param  list<int|null>  $openingStock
     *
     * @throws ValidationException
     */
    public function handle(ProductData $data, ?ProductWriteContext $context = null, array $openingStock = []): Product
    {
        return DB::transaction(function () use ($data, $context, $openingStock): Product {
            $product = $this->createProduct->handle($data, $context);

            $byCombination = $product->variants()->with('attributeValues')->get()
                ->keyBy(static fn (ProductVariant $v): string => VariantMatrix::key($v->attributeValues->pluck('id')->map(intval(...))->all()));

            $quantities = [];
            foreach ($openingStock as $index => $quantity) {
                $row = $data->variants[$index] ?? null;
                $variant = $row === null ? null : $byCombination->get(VariantMatrix::key($row->attributeValueIds));
                if ($variant instanceof ProductVariant && $quantity !== null && $quantity > 0) {
                    $quantities[$variant->id] = $quantity;
                }
            }

            $this->openingStock->record($product, $quantities, $context?->actorId);

            return $product;
        });
    }
}
