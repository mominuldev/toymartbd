<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Contracts\IssuedCoupon;
use App\Domain\Marketing\Contracts\PersonalCouponIssuer;
use App\Domain\Marketing\Contracts\PersonalCouponTerms;
use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Models\Coupon;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mints a personal, single-use coupon (P8 batch 3 birthday-reminder offer): `customer_id` set
 * (CouponEvaluator refuses anyone else, `not_for_customer`), `usage_limit_total` 1 and
 * `usage_limit_per_customer` 1, all products, valid from now to the end of the Dhaka day
 * `validDays` days later. The code is `PREFIX-` + 8 characters from the unambiguous alphabet
 * {@see GenerateCouponCodes} uses; the UNIQUE index on `coupons.code` is the final guard.
 * Runs inside the caller's transaction (a savepoint) — no external call.
 */
final class IssuePersonalCoupon implements PersonalCouponIssuer
{
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const int LENGTH = 8;

    public function issue(int $customerId, PersonalCouponTerms $terms, ?DateTimeInterface $at = null): IssuedCoupon
    {
        // Validity counts from the issuing moment (the send time), never the wall clock: a run
        // that processes an earlier slot, or a test, must not shift the expiry by a day.
        $issuedAt = CarbonImmutable::instance($at ?? DhakaDate::instant())->utc();

        return DB::transaction(static function () use ($customerId, $terms, $issuedAt): IssuedCoupon {
            $prefix = Coupon::normaliseCode($terms->prefix);
            do {
                $code = ($prefix === '' ? '' : $prefix.'-').self::random();
            } while (Coupon::query()->where('code', $code)->exists());

            $endsAt = DhakaDate::local($issuedAt)->startOfDay()->addDays($terms->validDays + 1)->utc();
            $percent = $terms->type === 'percent';

            $coupon = new Coupon([
                'description' => $terms->description,
                'batch' => $terms->batch,
                'type' => $percent ? CouponType::Percent : CouponType::Fixed,
                'value' => $terms->value,
                'max_discount' => $percent ? $terms->maxDiscount : null,
                'min_order_amount' => $terms->minOrderAmount,
                'usage_limit_total' => 1,
                'usage_limit_per_customer' => 1,
                'starts_at' => $issuedAt,
                'ends_at' => $endsAt,
                'applies_to' => ProductScopeType::All,
                'first_order_only' => false,
                'is_active' => true,
            ]);
            $coupon->code = $code;
            $coupon->customer_id = $customerId;
            $coupon->save();

            return new IssuedCoupon($coupon->id, $code, $endsAt);
        });
    }

    private static function random(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
