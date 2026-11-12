<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Exceptions\CartNotFound;
use App\Domain\Cart\Models\Cart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Finds an open cart by its opaque token (P4-CRT-01). A malformed token is treated
 * exactly like an unknown one, so the response never says which tokens exist.
 */
final class CartResolver
{
    public function find(?string $token): Cart
    {
        return $this->query($token)->first() ?? throw new CartNotFound;
    }

    /** For Actions: the cart row locked FOR UPDATE inside the caller's transaction. */
    public function lock(?string $token): Cart
    {
        return $this->query($token)->lockForUpdate()->first() ?? throw new CartNotFound;
    }

    /** @return Builder<Cart> */
    private function query(?string $token): Builder
    {
        $query = Cart::query()->open();

        return is_string($token) && Str::isUuid($token) ? $query->where('token', strtolower($token)) : $query->whereRaw('1 = 0');
    }

    public static function newToken(): string
    {
        return (string) Str::uuid();
    }
}
