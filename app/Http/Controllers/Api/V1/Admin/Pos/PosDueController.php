<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Pos;

use App\Domain\Pos\Actions\CollectDue;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Services\PosCustomers;
use App\Domain\Pos\Services\PosDueCollections;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Pos\CollectDueRequest;
use App\Http\Requests\Admin\Pos\QuoteDueCollectionRequest;
use App\Http\Resources\Admin\Pos\PosDueCollectionResource;
use App\Http\Resources\Admin\Pos\PosDueQuoteResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Due (baki) collected at the counter — owner decision 2026-11-15, docs/api/pos.md. On the
 * cashier's own open drawer (`pos.use`, PosSessionPolicy::operate): cash, bKash, Nagad or card
 * against a customer's open due, applied to their oldest POS orders first. Every figure is the
 * server's (rule 1).
 */
final class PosDueController extends ApiController
{
    /** `POST /pos/sessions/{session}/due-collections/quote` — FIFO preview, change, what stays owed. Writes nothing. */
    public function quote(QuoteDueCollectionRequest $request, PosSession $session, PosCustomers $customers, PosDueCollections $collections): PosDueQuoteResource
    {
        return new PosDueQuoteResource($collections->plan($customers->find($request->phone()), $request->tenders()));
    }

    /**
     * `POST /pos/sessions/{session}/due-collections` — take the money. Needs an
     * `Idempotency-Key`; a replay returns the first collection. 201.
     *
     * @response PosDueCollectionResource
     */
    #[OpenApiResponse(409, 'Drawer closed (`conflict`) or key in flight (`request-in-progress`)')]
    #[OpenApiResponse(422, 'No open due, non-cash above the due, a missing reference, or nothing tendered (`validation-error`)')]
    public function store(CollectDueRequest $request, PosSession $session, CollectDue $collect): JsonResponse
    {
        return (new PosDueCollectionResource($collect->handle($request->toData())))->response()->setStatusCode(201);
    }
}
