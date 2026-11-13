<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use App\Domain\Marketing\Models\Promotion;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /marketing/lookups/names?kind=products&ids=3,7` — the names behind the ids a saved coupon
 * or promotion scope holds, so its chips read "LEGO Classic", not "#3". Needs marketing.view.
 */
final class MarketingNameLookupRequest extends ApiFormRequest
{
    public const array KINDS = ['products', 'categories', 'brands'];

    public const int MAX_IDS = 200;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Promotion::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** products | categories | brands */
            'kind' => ['required', 'string', Rule::in(self::KINDS)],
            /** Comma-separated ids, at most 200. */
            'ids' => ['required', 'string', 'max:2000', 'regex:/^\d{1,10}(,\d{1,10}){0,199}$/'],
        ];
    }

    public function kind(): string
    {
        return $this->string('kind')->toString();
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', explode(',', $this->string('ids')->toString()))));
    }
}
