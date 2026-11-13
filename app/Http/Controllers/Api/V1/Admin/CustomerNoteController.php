<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Actions\AddCustomerNote;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Customer\StoreCustomerNoteRequest;
use App\Http\Resources\Admin\Customers\CustomerNoteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff notes on a customer (checklist §2.8). Append-only: no edit, no delete — the
 * author and time are the audit trail.
 */
final class CustomerNoteController extends ApiController
{
    /** `GET /customers/{customer}/notes` — needs customers.view. Newest first, pinned ones first. */
    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorize('view', $customer);

        $notes = CustomerNote::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return CustomerNoteResource::collection($notes);
    }

    /** `POST /customers/{customer}/notes` — needs customers.update. 201 with the note. */
    public function store(StoreCustomerNoteRequest $request, Customer $customer, AddCustomerNote $add): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();
        $note = $add->handle(
            $customer,
            $request->string('body')->toString(),
            $request->boolean('is_pinned'),
            is_int($userId) ? $userId : null,
        );

        return (new CustomerNoteResource($note))->response()->setStatusCode(201);
    }
}
