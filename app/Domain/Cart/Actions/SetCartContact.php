<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\CartResolver;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Abandoned-cart recovery (P7): the phone a shopper typed and whether they agreed to one
 * reminder about this cart. Stored E.164. Withdrawing consent (`consent = false`) is
 * always honoured, including after a reminder was scheduled — the sender re-checks.
 * Does not re-price and does not extend the cart's lifetime.
 */
final readonly class SetCartContact
{
    public function __construct(private CartResolver $carts) {}

    public function handle(string $token, PhoneNumber $phone, bool $consent, string $locale): Cart
    {
        return DB::transaction(function () use ($token, $phone, $consent, $locale): Cart {
            $cart = $this->carts->lock($token);
            $cart->phone = $phone->e164();
            $cart->marketing_consent = $consent;
            $cart->locale = in_array($locale, ['en', 'bn'], true) ? $locale : 'bn';
            $cart->contact_captured_at = now();
            $cart->timestamps = false;
            $cart->save();

            return $cart;
        });
    }
}
