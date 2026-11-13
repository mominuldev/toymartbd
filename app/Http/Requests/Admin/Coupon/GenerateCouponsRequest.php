<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Coupon;

use App\Domain\Marketing\Actions\GenerateCouponCodes;
use App\Domain\Marketing\Models\Coupon;
use Illuminate\Validation\Rule;

/**
 * `POST /coupons/generate` (coupon builder, P7) — the coupon document without `code`,
 * plus `count` (1–1000), `prefix` (optional, ≤ 12), `code_length` (6–16, default 8) and
 * `batch` (the campaign label the codes share). Needs marketing.manage.
 */
final class GenerateCouponsRequest extends CouponRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Coupon::class) ?? false;
    }

    protected function coupon(): ?Coupon
    {
        return null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['prefix', 'batch'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $this->merge([$key => Coupon::normaliseCode($value)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['code']);

        return $rules + [
            'count' => ['required', 'integer', 'min:1', 'max:'.GenerateCouponCodes::MAX],
            'prefix' => ['nullable', 'string', 'max:12', 'regex:'.self::CODE_PATTERN],
            'code_length' => ['sometimes', 'integer', 'between:6,16'],
            'batch' => ['required', 'string', 'max:40', 'regex:'.self::CODE_PATTERN, Rule::unique('coupons', 'batch')],
        ];
    }
}
