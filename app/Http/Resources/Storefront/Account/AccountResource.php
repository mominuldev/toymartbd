<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Customer\Models\Customer;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The signed-in customer's own profile (P4-STO-11). The customer sees their own number
 * in full (national form); it is never logged. `counts` feed the account home tiles —
 * `orders` is the Customer aggregate projection, refreshed on every order change.
 *
 * @property Customer $resource
 */
#[SchemaName('StorefrontAccount')]
final class AccountResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $customer = $this->resource;
        $phone = PhoneNumber::tryParse($customer->phone);

        return [
            'id' => $customer->id,
            /** Empty until the customer adds it (OTP sign-up asks only for the phone). */
            'name' => $customer->name,
            /** @var string */
            'phone' => $phone?->national() ?? '',
            /** @var string|null */
            'email' => $customer->email,
            /** @var string|null Y-m-d — the customer's own; birthday messages need it plus their consent. */
            'date_of_birth' => $customer->date_of_birth?->format('Y-m-d'),
            /** @var 'en'|'bn' */
            'preferred_language' => $customer->preferred_language === 'en' ? 'en' : 'bn',
            /** @var string|null */
            'phone_verified_at' => self::iso($customer->phone_verified_at),
            /** @var string|null */
            'member_since' => self::iso($customer->created_at),
            /** @var array{orders: int, wishlist: int, addresses: int} */
            'counts' => [
                'orders' => $customer->orders_count,
                'wishlist' => (int) $customer->wishlistItems()->count(),
                'addresses' => (int) $customer->addresses()->count(),
            ],
        ];
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:sP');
    }
}
