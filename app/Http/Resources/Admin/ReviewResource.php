<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A review in the admin moderation queue (P4-CAT-12). The customer's phone is masked
 * (rule 20); `is_verified_purchase` was derived from the order line on submission.
 *
 * @property Review $resource
 */
#[SchemaName('AdminReview')]
final class ReviewResource extends ApiResource
{
    public const array RELATIONS = ['product.images', 'customer', 'moderator'];

    /**
     * @return array{id: int, product: array{id: int, name: string, name_bn: string|null, slug: string, image_url: string|null}, customer: array{id: int, name: string, phone: string}|null, reviewer_name: string, rating: int, title: string|null, body: string|null, is_verified_purchase: bool, status: string, locale: string, admin_reply: string|null, admin_reply_at: string|null, moderated_by: array{id: int, name: string}|null, moderated_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        $review = $this->resource;
        $product = $review->product;
        $image = $product->images->first(static fn (ProductImage $i): bool => $i->is_primary) ?? $product->images->first();

        return [
            'id' => $review->id,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'name_bn' => $product->name_bn,
                'slug' => $product->slug,
                'image_url' => $image?->url(160),
            ],
            /** The reviewing customer, phone masked. */
            'customer' => $review->customer === null ? null : [
                'id' => $review->customer->id,
                'name' => $review->customer->name,
                'phone' => $review->customer->maskedPhone(),
            ],
            'reviewer_name' => $review->reviewer_name,
            /** 1–5 stars. */
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            /** Derived on submission from a delivered order line of the reviewer's own order. */
            'is_verified_purchase' => $review->is_verified_purchase,
            /** @var 'pending'|'approved'|'rejected' */
            'status' => $review->status->value,
            /** @var 'en'|'bn' */
            'locale' => $review->locale,
            'admin_reply' => $review->admin_reply,
            /** @format date-time */
            'admin_reply_at' => self::utcIso($review->admin_reply_at),
            'moderated_by' => $review->moderator === null ? null : ['id' => $review->moderator->id, 'name' => $review->moderator->name],
            /** @format date-time */
            'moderated_at' => self::utcIso($review->moderated_at),
            /**
             * @var string
             *
             * @format date-time
             */
            'created_at' => (string) self::utcIso($review->created_at),
        ];
    }

    private static function utcIso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
