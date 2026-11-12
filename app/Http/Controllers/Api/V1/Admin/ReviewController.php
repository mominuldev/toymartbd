<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Reviews\Actions\ModerateReview;
use App\Domain\Catalog\Reviews\Actions\ReplyToReview;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Review\ModerateReviewRequest;
use App\Http\Requests\Admin\Review\ReplyToReviewRequest;
use App\Http\Resources\Admin\ReviewResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Review moderation (P4-CAT-12). The queue reads with catalog.view; approve, reject and
 * reply need reviews.moderate. Approve and reject recompute the product's rating from
 * its approved reviews in the same transaction. Reviews are never deleted.
 */
final class ReviewController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /reviews` — newest first, cursor-paginated; `meta.counts` holds the number in
     * each status (for the tabs), unaffected by the filters.
     */
    #[QueryParameter('filter[status]', 'Only reviews in this status.', type: "'pending'|'approved'|'rejected'")]
    #[QueryParameter('filter[product_id]', 'Only reviews of this product.', type: 'int')]
    #[QueryParameter('filter[rating]', 'Only reviews with this many stars (1–5).', type: 'int')]
    #[QueryParameter('filter[verified]', 'Only verified (1) or unverified (0) purchases.', type: "'0'|'1'")]
    #[QueryParameter('filter[search]', 'Contains, on the title, body or reviewer name.', type: 'string')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Review::class);

        $reviews = QueryBuilder::for(Review::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? ReviewStatus::tryFrom($value) : null;
                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: pending, approved, rejected.');
                    }
                    $query->where('status', $status->value);
                }),
                AllowedFilter::callback('product_id', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || ! ctype_digit($value)) {
                        throw new BadRequestHttpException('filter[product_id] must be a positive integer id.');
                    }
                    $query->where('product_id', (int) $value);
                }),
                AllowedFilter::callback('rating', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || ! in_array($value, ['1', '2', '3', '4', '5'], true)) {
                        throw new BadRequestHttpException('filter[rating] must be 1 to 5.');
                    }
                    $query->where('rating', (int) $value);
                }),
                AllowedFilter::callback('verified', static function (Builder $query, mixed $value): void {
                    if (! in_array($value, ['0', '1'], true)) {
                        throw new BadRequestHttpException('filter[verified] must be 0 or 1.');
                    }
                    $query->where('is_verified_purchase', $value === '1');
                }),
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $like = '%'.addcslashes(is_string($value) ? $value : '', '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('body', 'like', $like)->orWhere('reviewer_name', 'like', $like));
                }),
            )
            ->defaultSort('-id')
            ->allowedSorts('id', 'rating')
            ->with(ReviewResource::RELATIONS)
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        $counts = Review::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return ReviewResource::collection($reviews)->additional(['meta' => ['counts' => [
            'pending' => (int) ($counts[ReviewStatus::Pending->value] ?? 0),
            'approved' => (int) ($counts[ReviewStatus::Approved->value] ?? 0),
            'rejected' => (int) ($counts[ReviewStatus::Rejected->value] ?? 0),
        ]]]);
    }

    /** `GET /reviews/{review}` — needs catalog.view. */
    public function show(Review $review): ReviewResource
    {
        $this->authorize('view', $review);

        return new ReviewResource($review->load(ReviewResource::RELATIONS));
    }

    /** `POST /reviews/{review}/approve` — publish it and recount the product's rating. Idempotent. */
    #[OpenApiResponse(403, 'Missing reviews.moderate (`forbidden`)')]
    public function approve(ModerateReviewRequest $request, Review $review, ModerateReview $moderate): ReviewResource
    {
        return new ReviewResource($moderate->handle($review, ReviewStatus::Approved, (int) $request->user()?->id)->load(ReviewResource::RELATIONS));
    }

    /** `POST /reviews/{review}/reject` — hide it (it stays on record) and recount the rating. Idempotent. */
    #[OpenApiResponse(403, 'Missing reviews.moderate (`forbidden`)')]
    public function reject(ModerateReviewRequest $request, Review $review, ModerateReview $moderate): ReviewResource
    {
        return new ReviewResource($moderate->handle($review, ReviewStatus::Rejected, (int) $request->user()?->id)->load(ReviewResource::RELATIONS));
    }

    /** `PUT /reviews/{review}/reply` — set or clear the shop's public reply. */
    #[OpenApiResponse(403, 'Missing reviews.moderate (`forbidden`)')]
    public function reply(ReplyToReviewRequest $request, Review $review, ReplyToReview $reply): ReviewResource
    {
        return new ReviewResource($reply->handle($review, $request->reply())->load(ReviewResource::RELATIONS));
    }
}
