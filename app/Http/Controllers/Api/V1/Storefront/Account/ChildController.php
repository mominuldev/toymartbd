<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Customer\Actions\RemoveCustomerChild;
use App\Domain\Customer\Actions\SaveCustomerChild;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerChild;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\SaveAccountChildRequest;
use App\Http\Resources\Storefront\Account\AccountChildResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "My kids" (P8 batch 3; Figma 239:13856): the signed-in parent's children, for birthday
 * reminders with a gift offer. No customer id in the path — the token is the customer; another
 * customer's child is a 404. A removed child is deleted, not soft-deleted.
 */
final class ChildController extends ApiController
{
    /** `GET /account/children` — in birthday order (month, day). */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function index(AccountReadRequest $request): AnonymousResourceCollection
    {
        return AccountChildResource::collection($request->customer()->children()->get());
    }

    /** `POST /account/children` — 201. At most 6. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'Not a real birthday, or 6 children already (`validation-error`)')]
    public function store(SaveAccountChildRequest $request, SaveCustomerChild $save): JsonResponse
    {
        $v = $request->child();
        $child = $save->handle($request->customer(), null, $v['name'], $v['month'], $v['day'], $v['year']);

        return (new AccountChildResource($child))->response()->setStatusCode(201);
    }

    /** `PATCH /account/children/{child}` — replaces every field. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(404, 'Not one of the customer\'s children (`not-found`)')]
    #[OpenApiResponse(422, 'Not a real birthday (`validation-error`)')]
    public function update(SaveAccountChildRequest $request, int $child, SaveCustomerChild $save): AccountChildResource
    {
        $customer = $request->customer();
        $v = $request->child();

        return new AccountChildResource($save->handle($customer, self::own($customer, $child), $v['name'], $v['month'], $v['day'], $v['year']));
    }

    /** `DELETE /account/children/{child}` — 204. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(404, 'Not one of the customer\'s children (`not-found`)')]
    public function destroy(AccountReadRequest $request, int $child, RemoveCustomerChild $remove): Response
    {
        $remove->handle(self::own($request->customer(), $child));

        return response()->noContent();
    }

    private static function own(Customer $customer, int $id): CustomerChild
    {
        return CustomerChild::query()->where('customer_id', $customer->id)->find($id)
            ?? throw new NotFoundHttpException;
    }
}
