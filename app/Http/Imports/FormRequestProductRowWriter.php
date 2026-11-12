<?php

declare(strict_types=1);

namespace App\Http\Imports;

use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Import\Contracts\ProductRowWriter;
use App\Domain\Catalog\Import\Enums\RowOutcome;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\CreateProductWithOpeningStock;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Seo\Actions\SaveProductWithSeo;
use App\Http\Requests\Admin\Product\ProductDocumentRequest;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;

/**
 * The import's row writer (P4-CAT-10): builds the real `StoreProductRequest` /
 * `UpdateProductRequest` for a row's document, validates and authorises it exactly as
 * the HTTP endpoints do (rules, after-hooks, `authorize()`), then runs the same
 * actions as {@see \App\Http\Controllers\Api\V1\Admin\ProductController::store()} and
 * `update()`. One set of product rules, whichever way a product arrives.
 */
final readonly class FormRequestProductRowWriter implements ProductRowWriter
{
    public function __construct(
        private Container $container,
        private CreateProductWithOpeningStock $createProduct,
        private UpdateProduct $updateProduct,
        private SaveProductWithSeo $save,
    ) {}

    public function write(array $document, ?Product $existing, User $actor): RowOutcome
    {
        $request = $this->request($document, $existing, $actor);
        $request->validateResolved();

        $context = $request->writeContext();

        if ($request instanceof StoreProductRequest) {
            if ($request->setsOpeningStock() && ! $actor->can('adjust', StockLevel::class)) {
                throw new AuthorizationException('Setting opening stock needs the inventory.adjust permission.');
            }

            $this->save->handle(
                null,
                fn (): Product => $this->createProduct->handle($request->toData(), $context, $request->openingStock()),
                $request->seoData(),
                $context->actorId,
            );

            return RowOutcome::Created;
        }

        /** @var Product $existing */
        $this->save->handle(
            $existing,
            fn (): Product => $this->updateProduct->handle($existing, $request->toData(), $context),
            $request->seoData(),
            $context->actorId,
        );

        return RowOutcome::Updated;
    }

    /** @param  array<string, mixed>  $document */
    private function request(array $document, ?Product $existing, User $actor): ProductDocumentRequest
    {
        $request = $existing === null
            ? StoreProductRequest::create('/api/v1/admin/products', 'POST', $document)
            : UpdateProductRequest::create('/api/v1/admin/products/'.$existing->id, 'PUT', $document);

        $request->headers->set('Accept', 'application/json');
        $request->setContainer($this->container);
        $request->setRedirector($this->container->make(Redirector::class));
        $request->setUserResolver(static fn (): User => $actor);

        if ($existing !== null) {
            $route = new Route(['PUT'], 'api/v1/admin/products/{product}', static fn (): null => null);
            $route->bind($request);
            $route->setParameter('product', $existing);
            $request->setRouteResolver(static fn (): Route => $route);
        }

        return $request;
    }
}
