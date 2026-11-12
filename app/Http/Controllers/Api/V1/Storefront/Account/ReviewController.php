<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Catalog\Reviews\Actions\SubmitReview;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\SubmitReviewRequest;
use App\Http\Resources\Storefront\Account\AccountReviewResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The signed-in customer writes a review (P4-CAT-12). It is `pending` until a moderator
 * approves it; `is_verified_purchase` is derived from a delivered order line, never sent.
 */
final class ReviewController extends ApiController
{
    /** `POST /account/reviews` — 201 with the pending review. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(409, 'Already reviewed this product, or this purchase (`conflict`)')]
    #[OpenApiResponse(422, 'Invalid fields, an unavailable product, an order line that is not your delivered purchase, or `is_verified_purchase` sent (`validation-error`)')]
    public function store(SubmitReviewRequest $request, SubmitReview $submit): JsonResponse
    {
        $review = $submit->handle($request->customer(), $request->toData());

        return (new AccountReviewResource($review))->response()->setStatusCode(201);
    }
}
