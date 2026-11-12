<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Catalog\Reviews\Models\Review;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The customer's own review as submitted (P4-CAT-12): `pending` until a moderator
 * approves it.
 *
 * @property Review $resource
 */
#[SchemaName('AccountReview')]
final class AccountReviewResource extends ApiResource
{
    /**
     * @return array{id: int, product_id: int, rating: int, title: string|null, body: string|null, reviewer_name: string, is_verified_purchase: bool, status: string}
     */
    public function toArray(Request $request): array
    {
        $review = $this->resource;

        return [
            'id' => $review->id,
            'product_id' => $review->product_id,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'reviewer_name' => $review->reviewer_name,
            'is_verified_purchase' => $review->is_verified_purchase,
            /** @var 'pending'|'approved'|'rejected' */
            'status' => $review->status->value,
        ];
    }
}
