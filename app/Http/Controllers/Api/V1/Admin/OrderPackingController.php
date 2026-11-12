<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\BulkMarkOrdersPacked;
use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\BulkPackOrdersRequest;
use App\Http\Resources\Admin\OrderBulkResultResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Bulk "mark packed" from the order board (P4-ORD-08). */
final class OrderPackingController extends ApiController
{
    /**
     * `POST /orders/bulk/pack` — moves each order to `ready_to_ship` on its own; returns
     * one result per id (`done`, `conflict` with the current status, `not_found`) and
     * `meta.done` / `meta.failed`. A partial failure is a 200 with per-order results.
     * Needs orders.update.
     */
    public function bulkPack(BulkPackOrdersRequest $request, BulkMarkOrdersPacked $bulk): AnonymousResourceCollection
    {
        $outcomes = $bulk->handle($request->orderIds(), $request->user()?->id);
        $done = count(array_filter($outcomes, static fn (BulkOrderOutcome $o): bool => $o->succeeded()));

        return OrderBulkResultResource::collection($outcomes)
            ->additional(['meta' => ['done' => $done, 'failed' => count($outcomes) - $done]]);
    }
}
