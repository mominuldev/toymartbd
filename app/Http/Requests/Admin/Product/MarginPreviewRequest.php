<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Closure;

/**
 * `POST /products/margin-preview` — margins for prices and costs the form has not
 * saved yet, so the admin never computes one (rule 1). Needs catalog.view_cost.
 * Money is a decimal string; `rows[i]` answers `rows[i]`.
 */
final class MarginPreviewRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewCost', Product::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['nullable', 'string', 'regex:'.ProductDocumentRequest::MONEY_PATTERN];

        return [
            'vat_rate' => ['nullable', 'string', 'regex:'.ProductDocumentRequest::VAT_PATTERN, static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && bccomp($value, '100', 2) > 0) {
                    $fail('The VAT rate cannot be above 100%.');
                }
            }],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.price' => $money,
            'rows.*.cost_price' => $money,
        ];
    }

    public function vatRate(): string
    {
        $vat = $this->validated('vat_rate');

        return is_string($vat) && $vat !== '' ? $vat : '0';
    }

    /** @return list<array{price: Money, cost: Money}> */
    public function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values((array) $this->validated('rows'));

        return array_map(static fn (array $row): array => [
            'price' => is_string($row['price'] ?? null) && $row['price'] !== '' ? Money::of($row['price']) : Money::zero(),
            'cost' => is_string($row['cost_price'] ?? null) && $row['cost_price'] !== '' ? Money::of($row['cost_price']) : Money::zero(),
        ], $rows);
    }
}
