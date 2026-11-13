<?php

declare(strict_types=1);

namespace App\Domain\Notification\CartRecovery;

use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Enums\CartRecoverySkip;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationPreference;
use App\Support\PhoneNumber;

/**
 * Who a recovery reminder would go to, and whether we may send it (P7). A cart reminder
 * is a **marketing** message (NotificationCategory, off by default):
 *
 *  - an explicit SMS-marketing opt-out on the customer (the cart's, or the one this phone
 *    belongs to) always wins — even over a guest's tick on the cart;
 *  - otherwise an explicit opt-in on the customer, or the guest's `marketing_consent` on
 *    this cart, allows it;
 *  - otherwise no.
 *
 * Checked when the cart is detected and again by the job just before sending.
 */
final class RecoveryConsent
{
    /** @return array{phone: PhoneNumber|null, customer: Customer|null, skip: CartRecoverySkip|null} */
    public function check(Cart $cart): array
    {
        $customer = $cart->customer_id === null ? null : Customer::query()->find($cart->customer_id);
        $phone = PhoneNumber::tryParse((string) ($cart->phone ?? $customer->phone ?? ''));
        if ($phone === null) {
            return ['phone' => null, 'customer' => $customer, 'skip' => CartRecoverySkip::NoPhone];
        }
        $customer ??= Customer::query()->where('phone', $phone->e164())->first();

        $explicit = $customer === null ? null : NotificationPreference::query()
            ->where('customer_id', $customer->id)
            ->where('channel', NotificationChannel::Sms->value)
            ->where('category', NotificationCategory::Marketing->value)
            ->first();

        $skip = match (true) {
            $explicit !== null && ! $explicit->enabled => CartRecoverySkip::OptedOut,
            $explicit?->enabled === true || $cart->marketing_consent => null,
            default => CartRecoverySkip::NoConsent,
        };

        return ['phone' => $phone, 'customer' => $customer, 'skip' => $skip];
    }
}
