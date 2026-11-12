<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\Actions;

use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Reviews\Contracts\DeliveredPurchases;
use App\Domain\Catalog\Reviews\DTOs\ReviewData;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Customer\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in customer reviews a product (P4-CAT-12). The review starts `pending`
 * and does not touch the rating until a moderator approves it.
 *
 * `is_verified_purchase` is derived here and nowhere else:
 * - an `order_item_id` given by the client must be a delivered line of this
 *   customer's own order for this product, or the review is refused (422) — a review
 *   cannot claim a purchase it did not make;
 * - with none given, the customer's oldest delivered, not-yet-reviewed line for the
 *   product is linked when there is one;
 * - otherwise the review is stored unverified.
 *
 * One review per customer per product (409 on a second).
 */
final readonly class SubmitReview
{
    public function __construct(private DeliveredPurchases $purchases) {}

    /** @throws ValidationException|CatalogConflict */
    public function handle(Customer $customer, ReviewData $data): Review
    {
        if ($data->orderItemId !== null && ! $this->purchases->isDelivered($data->orderItemId, $customer->id, $data->productId)) {
            throw ValidationException::withMessages([
                'order_item_id' => 'This is not a delivered purchase of this product on your account.',
            ]);
        }

        try {
            return DB::transaction(function () use ($customer, $data): Review {
                $existing = Review::query()
                    ->where('customer_id', $customer->id)
                    ->where('product_id', $data->productId)
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    throw new CatalogConflict('You have already reviewed this product.');
                }

                $orderItemId = $data->orderItemId ?? $this->unreviewedLine($customer->id, $data->productId);

                return Review::query()->create([
                    'product_id' => $data->productId,
                    'customer_id' => $customer->id,
                    'order_item_id' => $orderItemId,
                    'reviewer_name' => $data->reviewerName ?? self::displayName($customer->name),
                    'rating' => $data->rating,
                    'title' => $data->title,
                    'body' => $data->body,
                    'is_verified_purchase' => $orderItemId !== null,
                    'status' => ReviewStatus::Pending,
                    'locale' => $data->locale,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new CatalogConflict('This purchase has already been reviewed.');
        }
    }

    private function unreviewedLine(int $customerId, int $productId): ?int
    {
        $lines = $this->purchases->deliveredLines($customerId, $productId);

        if ($lines === []) {
            return null;
        }

        $reviewed = Review::query()->whereIn('order_item_id', $lines)->pluck('order_item_id')->all();

        foreach ($lines as $line) {
            if (! in_array($line, $reviewed, true)) {
                return $line;
            }
        }

        return null;
    }

    /** "Nusrat Jahan" → "Nusrat J." — a public review never shows a shopper's full name by default. */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return 'Customer';
        }

        $first = $parts[0];
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1).'.' : '';

        return mb_substr(trim($first.' '.$last), 0, 120);
    }
}
