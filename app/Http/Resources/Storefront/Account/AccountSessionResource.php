<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Customer\DTOs\CustomerSession;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The answer to a correct sign-in code: the customer's bearer token — returned once, to
 * the Next.js server, which keeps it in an httpOnly cookie (rule 28) — and the cart the
 * guest cart was merged into, whose token replaces the storefront's cart cookie.
 *
 * @property CustomerSession $resource
 */
#[SchemaName('StorefrontAccountSession')]
final class AccountSessionResource extends ApiResource
{
    public function __construct(CustomerSession $session, private readonly ?PricedCart $cart)
    {
        parent::__construct($session);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            /** Sanctum bearer token for `/storefront/account/*`. Shown once. */
            'token' => $session->plainTextToken,
            /** @var string */
            'expires_at' => $session->expiresAt->format('Y-m-d\TH:i:sP'),
            'is_new_customer' => $session->isNewCustomer,
            'customer' => new AccountResource($session->customer),
            /** @var array{token: string, item_count: int}|null */
            'cart' => $this->cart === null ? null : ['token' => $this->cart->cart->token, 'item_count' => $this->cart->cart->item_count],
        ];
    }
}
