<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\CreateAttributeValue;
use App\Domain\Catalog\Actions\DeleteAttributeValue;
use App\Domain\Catalog\Actions\ReorderAttributeValues;
use App\Domain\Catalog\Actions\UpdateAttributeValue;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Attribute\ReorderAttributeValuesRequest;
use App\Http\Requests\Admin\Attribute\StoreAttributeValueRequest;
use App\Http\Requests\Admin\Attribute\UpdateAttributeValueRequest;
use App\Http\Resources\Admin\AttributeResource;
use App\Http\Resources\Admin\AttributeValueResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Values of one attribute, nested under it (`/attributes/{attribute}/values`).
 * A value is deleted only while no variant uses it (409 otherwise).
 */
final class AttributeValueController extends ApiController
{
    /** `POST /attributes/{attribute}/values` — appended last, needs catalog.create. */
    public function store(StoreAttributeValueRequest $request, ProductAttribute $attribute, CreateAttributeValue $createValue): JsonResponse
    {
        $value = $createValue->handle($attribute, $request->toData());

        return (new AttributeValueResource($value))->response()->setStatusCode(201);
    }

    /** `PATCH /attributes/{attribute}/values/{value}` — needs catalog.update. */
    public function update(UpdateAttributeValueRequest $request, ProductAttribute $attribute, ProductAttributeValue $value, UpdateAttributeValue $updateValue): AttributeValueResource
    {
        return new AttributeValueResource($updateValue->handle($value, $request->changes()));
    }

    /**
     * `POST /attributes/{attribute}/values/reorder` — returns the attribute with its
     * values in the new order; needs catalog.update.
     */
    public function reorder(ReorderAttributeValuesRequest $request, ProductAttribute $attribute, ReorderAttributeValues $reorder): AttributeResource
    {
        return new AttributeResource($reorder->handle($attribute, $request->ids())->load('values'));
    }

    /** `DELETE /attributes/{attribute}/values/{value}` — needs catalog.archive. */
    #[OpenApiResponse(409, 'A product variant uses this value (`conflict`)')]
    public function destroy(ProductAttribute $attribute, ProductAttributeValue $value, DeleteAttributeValue $deleteValue): Response
    {
        $this->authorize('delete', $value);

        $deleteValue->handle($value);

        return response()->noContent();
    }
}
