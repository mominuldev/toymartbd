<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * Set or clear the shop's public reply to a review (P4-CAT-12). Shown under an
 * approved review on the product page.
 */
final readonly class ReplyToReview
{
    public function handle(Review $review, ?string $reply): Review
    {
        return DB::transaction(function () use ($review, $reply): Review {
            $review = Review::query()->lockForUpdate()->findOrFail($review->id);

            $review->forceFill([
                'admin_reply' => $reply,
                'admin_reply_at' => $reply === null ? null : now(),
            ])->save();

            if ($review->status === ReviewStatus::Approved) {
                $slug = Product::withTrashed()->whereKey($review->product_id)->value('slug');
                if (is_string($slug)) {
                    ProductChanged::dispatch($review->product_id, $slug);
                }
            }

            return $review;
        });
    }
}
