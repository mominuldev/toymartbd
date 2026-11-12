<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Review;

use App\Http\Requests\ApiFormRequest;

/**
 * `GET /storefront/products/{slug}/reviews` — approved reviews, newest first, cursor
 * paginated. Only `cursor` and `per_page` are accepted (rule 16).
 */
final class ProductReviewsQuery extends ApiFormRequest
{
    public const int PER_PAGE = 10;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:512'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function perPage(): int
    {
        return $this->has('per_page') ? $this->integer('per_page') : self::PER_PAGE;
    }
}
