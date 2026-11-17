<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\ProductCopy\ProductCopyFacts;
use App\Domain\Ai\ProductCopy\ProductCopyWriter;
use App\Domain\Ai\Services\AiSettings;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Ai\ProductCopyRequest;
use App\Http\Resources\Admin\Ai\ProductCopyFactsResource;
use App\Http\Resources\Admin\Ai\ProductCopyResource;

/** Product › Write with AI (Figma 538:82911). Suggest only: nothing is saved here. */
final class ProductCopyController extends ApiController
{
    /**
     * `POST /products/{product}/ai-copy` — a draft title, description and SEO texts from the
     * product's own facts. Saves nothing; the editor fills its form and Save product saves.
     * 409 `ai-not-shared` when product details are not shared with AI (Integrations › AI assistant);
     * 409 `ai-not-configured`, 409 `ai-cap-reached`, 503 `ai-unavailable` from the AI layer.
     */
    public function store(ProductCopyRequest $request, Product $product, ProductCopyWriter $writer, AiSettings $settings): ProductCopyResource
    {
        if (! $settings->share()['products']) {
            throw new AiException(AiException::NOT_SHARED, 'Product details are not shared with AI. Turn on “Product details” in Integrations › AI assistant to write with AI.');
        }

        $id = $request->user()?->getAuthIdentifier();
        $draft = $writer->write($product, $request->fields(), $request->languages(), $request->tone(), $request->keywords(), is_int($id) ? $id : null);

        return new ProductCopyResource($draft);
    }

    /** `GET /products/{product}/ai-copy/facts` — the facts a draft would use, shown before writing. No AI call. */
    public function facts(Product $product): ProductCopyFactsResource
    {
        $this->authorize('update', $product);

        return new ProductCopyFactsResource(ProductCopyFacts::of($product)->forDisplay());
    }
}
