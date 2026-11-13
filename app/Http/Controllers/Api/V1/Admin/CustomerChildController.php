<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Actions\RemoveCustomerChild;
use App\Domain\Customer\Actions\SaveCustomerChild;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Customer\SaveCustomerChildRequest;
use App\Http\Resources\Admin\Customers\CustomerChildResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's children, edited by staff on the profile (P8 batch 3; Figma B · 18 241:41374
 * shows them in the Profile card). Children's data: every write needs customers.update AND
 * customers.view_pii; the list is read on `GET /customers/{customer}` (hidden without
 * view_pii). A child id that is not this customer's is a 404.
 */
final class CustomerChildController extends ApiController
{
    /** `POST /customers/{customer}/children` — 201 with the child. At most 6 per customer. */
    #[OpenApiResponse(403, 'Missing customers.update or customers.view_pii (`forbidden`)')]
    #[OpenApiResponse(422, 'Not a real birthday, or the customer already has 6 children (`validation-error`)')]
    public function store(SaveCustomerChildRequest $request, Customer $customer, SaveCustomerChild $save): JsonResponse
    {
        $v = $request->child();
        $child = $save->handle($customer, null, $v['name'], $v['month'], $v['day'], $v['year']);

        return (new CustomerChildResource($child))->response()->setStatusCode(201);
    }

    /** `PATCH /customers/{customer}/children/{child}` — replaces every field. */
    #[OpenApiResponse(403, 'Missing customers.update or customers.view_pii (`forbidden`)')]
    #[OpenApiResponse(404, 'Not one of this customer\'s children (`not-found`)')]
    #[OpenApiResponse(422, 'Not a real birthday (`validation-error`)')]
    public function update(SaveCustomerChildRequest $request, Customer $customer, int $child, SaveCustomerChild $save): CustomerChildResource
    {
        $v = $request->child();

        return new CustomerChildResource($save->handle($customer, self::own($customer, $child), $v['name'], $v['month'], $v['day'], $v['year']));
    }

    /** `DELETE /customers/{customer}/children/{child}` — 204. Deleted, not soft-deleted. */
    #[OpenApiResponse(403, 'Missing customers.update or customers.view_pii (`forbidden`)')]
    #[OpenApiResponse(404, 'Not one of this customer\'s children (`not-found`)')]
    public function destroy(Customer $customer, int $child, RemoveCustomerChild $remove): Response
    {
        $this->authorize('manageChildren', $customer);
        $remove->handle(self::own($customer, $child));

        return response()->noContent();
    }

    private static function own(Customer $customer, int $id): CustomerChild
    {
        return CustomerChild::query()->where('customer_id', $customer->id)->find($id)
            ?? throw new NotFoundHttpException;
    }
}
