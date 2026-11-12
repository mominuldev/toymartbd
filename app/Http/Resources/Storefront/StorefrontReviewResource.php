<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Reviews\Models\Review;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An approved review on the product page (P4-CAT-12). Nothing that identifies the
 * customer beyond the display name they chose.
 *
 * @property Review $resource
 */
#[SchemaName('StorefrontReview')]
final class StorefrontReviewResource extends ApiResource
{
    /**
     * @return array{id: int, reviewer_name: string, rating: int, title: string|null, body: string|null, is_verified_purchase: bool, locale: string, admin_reply: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        $review = $this->resource;

        return [
            'id' => $review->id,
            'reviewer_name' => $review->reviewer_name,
            /** 1–5 stars. */
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'is_verified_purchase' => $review->is_verified_purchase,
            /**
             * The language the shopper wrote in.
             *
             * @var 'en'|'bn'
             */
            'locale' => $review->locale,
            /** The shop's public reply, if any. */
            'admin_reply' => $review->admin_reply,
            /**
             * @var string
             *
             * @format date-time
             */
            'created_at' => self::utcIso($review->created_at),
        ];
    }

    private static function utcIso(?DateTimeInterface $at): string
    {
        return $at === null ? '' : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
