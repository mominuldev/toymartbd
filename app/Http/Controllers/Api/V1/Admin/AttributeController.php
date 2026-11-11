<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\CreateAttribute;
use App\Domain\Catalog\Actions\DeleteAttribute;
use App\Domain\Catalog\Actions\UpdateAttribute;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Attribute\StoreAttributeRequest;
use App\Http\Requests\Admin\Attribute\UpdateAttributeRequest;
use App\Http\Resources\Admin\AttributeResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Product attributes (P4-CAT-09) with their values. Attributes are few (Color,
 * Size, …), so the list is unpaginated, in display order, values included.
 * `DELETE` removes an attribute no variant uses; otherwise it is a 409.
 */
final class AttributeController extends ApiController
{
    public const int MAX_ATTRIBUTES = 200;

    /** `GET /attributes` — every attribute with its values, by position; needs catalog.view. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductAttribute::class);

        $attributes = ProductAttribute::query()
            ->with('values')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::MAX_ATTRIBUTES)
            ->get();

        return AttributeResource::collection($attributes);
    }

    /** `POST /attributes` — needs catalog.create. */
    public function store(StoreAttributeRequest $request, CreateAttribute $createAttribute): JsonResponse
    {
        $attribute = $createAttribute->handle($request->toData());

        return (new AttributeResource($attribute->load('values')))->response()->setStatusCode(201);
    }

    /** `GET /attributes/{attribute}` — needs catalog.view. */
    public function show(ProductAttribute $attribute): AttributeResource
    {
        $this->authorize('view', $attribute);

        return new AttributeResource($attribute->load('values'));
    }

    /** `PATCH /attributes/{attribute}` — needs catalog.update. */
    public function update(UpdateAttributeRequest $request, ProductAttribute $attribute, UpdateAttribute $updateAttribute): AttributeResource
    {
        return new AttributeResource($updateAttribute->handle($attribute, $request->changes())->load('values'));
    }

    /** `DELETE /attributes/{attribute}` — deletes it and its values, needs catalog.archive. */
    #[OpenApiResponse(409, 'A product variant uses one of its values (`conflict`)')]
    public function destroy(ProductAttribute $attribute, DeleteAttribute $deleteAttribute): Response
    {
        $this->authorize('delete', $attribute);

        $deleteAttribute->handle($attribute);

        return response()->noContent();
    }
}
