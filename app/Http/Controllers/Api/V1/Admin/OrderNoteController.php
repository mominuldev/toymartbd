<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\AddOrderNote;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\StoreOrderNoteRequest;
use App\Http\Resources\Admin\OrderNoteResource;
use Illuminate\Http\JsonResponse;

/** Internal notes on an order (P4-ORD-05). Append-only: there is no edit or delete. */
final class OrderNoteController extends ApiController
{
    /** `POST /orders/{order}/notes` — needs orders.update. 201 with the note. */
    public function store(StoreOrderNoteRequest $request, Order $order, AddOrderNote $addNote): JsonResponse
    {
        $note = $addNote->handle(
            $order,
            $request->string('body')->toString(),
            $request->boolean('is_customer_visible'),
            $request->user()?->id,
        );

        return (new OrderNoteResource($note))->response()->setStatusCode(201);
    }
}
