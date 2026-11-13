<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\DTOs\CouponData;
use App\Domain\Marketing\Models\Coupon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Coupon builder bulk codes (P7): `count` single-purpose coupons on the same terms, each
 * with a unique random code `PREFIX-XXXXXXXX`, grouped under one `batch`. The alphabet
 * leaves out 0/O and 1/I/L, which customers mistype when reading a code off an SMS.
 * Uniqueness is checked against existing codes and inside the batch; the UNIQUE index
 * on `coupons.code` is the final guard.
 */
final class GenerateCouponCodes
{
    public const int MAX = 1000;

    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** @return list<Coupon> */
    public function handle(CouponData $terms, int $count, string $prefix, int $length, string $batch, ?int $createdBy = null): array
    {
        $count = max(1, min(self::MAX, $count));
        $prefix = Coupon::normaliseCode($prefix);

        return DB::transaction(function () use ($terms, $count, $prefix, $length, $batch, $createdBy): array {
            $codes = [];
            $attempts = 0;
            while (count($codes) < $count) {
                if (++$attempts > $count * 20) {
                    throw new RuntimeException('Could not generate enough unique codes; use a longer code length.');
                }
                $need = $count - count($codes);
                $candidates = [];
                for ($i = 0; $i < $need; $i++) {
                    $candidates[] = ($prefix === '' ? '' : $prefix.'-').self::random($length);
                }
                $candidates = array_values(array_diff(array_unique($candidates), $codes));
                $taken = Coupon::query()->whereIn('code', $candidates)->pluck('code')->all();
                array_push($codes, ...array_values(array_diff($candidates, $taken)));
            }

            $coupons = [];
            foreach (array_slice($codes, 0, $count) as $code) {
                $coupon = new Coupon($terms->toAttributes());
                $coupon->code = $code;
                $coupon->batch = $batch;
                $coupon->created_by = $createdBy;
                $coupon->save();
                $coupons[] = $coupon;
            }

            return $coupons;
        });
    }

    private static function random(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
