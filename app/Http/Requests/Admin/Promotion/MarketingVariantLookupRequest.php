<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Promotion;

use App\Domain\Marketing\Models\Promotion;
use App\Http\Requests\ApiFormRequest;

/**
 * `GET /marketing/lookups/variants?q=` — the flash-sale line picker. Needs what building a
 * promotion needs (marketing.manage), not orders.create: marketing staff set flash prices
 * without being able to place orders.
 */
final class MarketingVariantLookupRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('create', Promotion::class) || $user->can('update', Promotion::class));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Name, Bangla name, SKU or barcode, at least 2 characters. */
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function term(): string
    {
        return trim($this->string('q')->toString());
    }
}
