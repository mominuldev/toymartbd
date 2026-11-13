<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Review;

use App\Http\Requests\ApiFormRequest;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /storefront/products/{slug}/reviews` — approved reviews, newest first, cursor
 * paginated. Only `cursor` and `per_page` are accepted (rule 16): anything else is a 400.
 */
final class ProductReviewsQuery extends ApiFormRequest
{
    public const int PER_PAGE = 10;

    /** `locale` is ResolveLocale's. */
    public const array PARAMETERS = ['cursor', 'per_page', 'locale'];

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

    /** @throws BadRequestHttpException */
    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($this->query())), self::PARAMETERS);

        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown query parameter(s): '.implode(', ', $unknown).'. Allowed: cursor, per_page.');
        }
    }

    public function perPage(): int
    {
        return $this->has('per_page') ? $this->integer('per_page') : self::PER_PAGE;
    }
}
