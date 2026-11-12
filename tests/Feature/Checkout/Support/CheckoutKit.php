<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout\Support;

use App\Domain\Cart\Actions\AddCartLine;
use App\Domain\Cart\Actions\ApplyCoupon;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Checkout\CheckoutData;
use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * Checkout fixtures (P4-ORD-02). Needs GeographySeeder + DeliverySeeder. The default
 * address is Chattogram (district 8) / Rangunia (upazila 65): outside Dhaka, ৳130 for a
 * parcel up to 1 kg. Bangla names and addresses throughout (rule 34).
 */
final class CheckoutKit
{
    public const int DISTRICT = 8;

    public const int UPAZILA = 65;

    public const string PHONE = '01712345678';

    /**
     * A cart with these lines; returns its token.
     *
     * @param  array<int, int>  $lines  variant id → quantity
     */
    public static function cart(array $lines, ?string $coupon = null): string
    {
        $token = null;
        foreach ($lines as $variantId => $quantity) {
            $token = app(AddCartLine::class)->handle($token, $variantId, $quantity)->cart->token;
        }
        if ($coupon !== null && $token !== null) {
            app(ApplyCoupon::class)->handle($token, $coupon);
        }

        return (string) $token;
    }

    /** What the storefront would show as the total for this cart, address and phone. */
    public static function total(string $token, int $district = self::DISTRICT, ?int $upazila = self::UPAZILA, string $phone = self::PHONE): string
    {
        $cart = Cart::query()->where('token', $token)->firstOrFail();

        return app(PricingPipeline::class)->price(new PricingInput(
            lines: $cart->lines(),
            couponCode: $cart->coupon?->code,
            district: District::query()->findOrFail($district),
            upazila: $upazila === null ? null : Upazila::query()->findOrFail($upazila),
            phone: PhoneNumber::parse($phone),
        ))->grandTotal->toDecimal();
    }

    /**
     * The API body for a cart (expected_total = the real total unless overridden).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(string $token, array $overrides = []): array
    {
        $body = [
            'name' => 'রাহেলা খাতুন',
            'phone' => self::PHONE,
            'district_id' => self::DISTRICT,
            'upazila_id' => self::UPAZILA,
            'area' => 'আগ্রাবাদ',
            'address_line' => 'বাড়ি ১২, রোড ৫, ব্লক সি',
            'landmark' => 'জামে মসজিদের পাশে, ৩য় তলা',
            'payment_method' => 'cod',
            ...$overrides,
        ];

        if (! array_key_exists('expected_total', $overrides)) {
            $body['expected_total'] = self::total(
                $token,
                (int) $body['district_id'],
                $body['upazila_id'] === null ? null : (int) $body['upazila_id'],
                (string) $body['phone'],
            );
        }

        return $body;
    }

    /** The Action-level input (no HTTP). */
    public static function data(string $token, string $phone = self::PHONE, ?string $expected = null, string $name = 'রাহেলা খাতুন'): CheckoutData
    {
        return new CheckoutData(
            cartToken: $token,
            name: $name,
            phone: PhoneNumber::parse($phone),
            district: District::query()->with('division')->findOrFail(self::DISTRICT),
            upazila: Upazila::query()->findOrFail(self::UPAZILA),
            area: 'আগ্রাবাদ',
            addressLine: 'বাড়ি ১২, রোড ৫, ব্লক সি',
            expectedTotal: Money::of($expected ?? self::total($token, phone: $phone)),
            landmark: 'জামে মসজিদের পাশে',
        );
    }
}
