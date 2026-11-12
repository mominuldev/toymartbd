<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Catalog\Reviews\Services\RatingAggregates;
use Illuminate\Support\Facades\DB;

/**
 * Approve or reject a review (P4-CAT-12) and recompute the product's rating from its
 * approved reviews in the same transaction, so the aggregate is never out of step
 * with what the storefront lists. Moving an approved review back to rejected (or
 * the reverse) is allowed; re-applying the same status changes nothing. When the
 * public rating may have changed, ProductChanged fires after commit (PDP
 * revalidation, search re-index).
 */
final readonly class ModerateReview
{
    public function __construct(private RatingAggregates $aggregates) {}

    public function handle(Review $review, ReviewStatus $status, int $moderatorId): Review
    {
        return DB::transaction(function () use ($review, $status, $moderatorId): Review {
            $review = Review::query()->lockForUpdate()->findOrFail($review->id);

            if ($review->status === $status) {
                return $review;
            }

            $touchesPublic = $review->status === ReviewStatus::Approved || $status === ReviewStatus::Approved;

            $review->forceFill([
                'status' => $status,
                'moderated_by' => $moderatorId,
                'moderated_at' => now(),
            ])->save();

            $this->aggregates->recompute($review->product_id);

            if ($touchesPublic) {
                $slug = Product::withTrashed()->whereKey($review->product_id)->value('slug');
                if (is_string($slug)) {
                    ProductChanged::dispatch($review->product_id, $slug);
                }
            }

            return $review;
        });
    }
}
