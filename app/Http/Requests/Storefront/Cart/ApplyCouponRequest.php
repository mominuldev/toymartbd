<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Domain\Marketing\Models\Coupon;

/** `POST /cart/coupon` `{ code }` — case-insensitive. A rejected code is a 422 on `code` with the reason. */
final class ApplyCouponRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:'.Coupon::CODE_MAX],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
