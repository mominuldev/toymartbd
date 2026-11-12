<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Fulfilment\DTOs\DeliveryQuote;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A delivery quote (P4-STO-06). Money is a decimal string; dates are Dhaka calendar
 * dates (`Y-m-d`). When `serviceable` is false we do not deliver to that upazila:
 * `zone`, `courier`, the charges and `eta` are null — the storefront shows the
 * NotServiced state and still lets the customer add to cart.
 *
 * @property DeliveryQuote $resource
 */
#[SchemaName('DeliveryQuote')]
final class DeliveryQuoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        $eta = $quote->eta;

        return [
            'serviceable' => $quote->serviceable,
            /** @var 'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka'|null */
            'zone' => $quote->zone?->value,
            /** @var array{id: int, name: string, name_bn: string} */
            'district' => ['id' => $quote->district->id, 'name' => $quote->district->name, 'name_bn' => $quote->district->name_bn],
            /** @var array{id: int, name: string, name_bn: string}|null */
            'upazila' => $quote->upazila === null ? null : ['id' => $quote->upazila->id, 'name' => $quote->upazila->name, 'name_bn' => $quote->upazila->name_bn],
            /**
             * The district alone does not settle the charge: ask for the upazila.
             *
             * @var bool
             */
            'upazila_required' => $quote->upazilaRequired,
            /** @var array{code: string, name: string, name_bn: string|null}|null */
            'courier' => $quote->courier === null ? null : ['code' => $quote->courier->code, 'name' => $quote->courier->name, 'name_bn' => $quote->courier->name_bn],
            /**
             * The goods value the quote is for (variant price × quantity).
             *
             * @var string
             */
            'subtotal' => $quote->subtotal->toDecimal(),
            'weight_grams' => $quote->weightGrams,
            /** @var string|null */
            'base_charge' => $quote->baseCharge?->toDecimal(),
            /** @var string|null */
            'weight_surcharge' => $quote->weightSurcharge?->toDecimal(),
            /**
             * What the customer pays for delivery — "0.00" when free.
             *
             * @var string|null
             */
            'charge' => $quote->charge?->toDecimal(),
            /**
             * base_charge + weight_surcharge: the charge before any free delivery (struck
             * through when free).
             *
             * @var string|null
             */
            'standard_charge' => $quote->baseCharge === null || $quote->weightSurcharge === null ? null : $quote->baseCharge->plus($quote->weightSurcharge)->toDecimal(),
            /**
             * `progress_percent` is the subtotal as a whole percent of the threshold (0–100,
             * rounded down) for the free-delivery meter; null when there is no threshold.
             *
             * @var array{applied: bool, reason: 'threshold'|'promotion'|null, threshold: string|null, remaining: string|null, progress_percent: int|null}
             */
            'free_shipping' => [
                'applied' => $quote->freeShipping,
                'reason' => $quote->freeShippingReason,
                'threshold' => $quote->freeShippingThreshold?->toDecimal(),
                'remaining' => $quote->freeShippingRemaining?->toDecimal(),
                'progress_percent' => self::progress($quote),
            ],
            /** @var array{dispatch_date: string, earliest: string, latest: string, min_days: int, max_days: int, cutoff_time: string, cutoff_passed: bool, timezone: string}|null */
            'eta' => $eta === null ? null : [
                'dispatch_date' => $eta->dispatchDate->toDateString(),
                'earliest' => $eta->earliest->toDateString(),
                'latest' => $eta->latest->toDateString(),
                'min_days' => $eta->minDays,
                'max_days' => $eta->maxDays,
                'cutoff_time' => $eta->cutoffTime,
                'cutoff_passed' => $eta->cutoffPassed,
                'timezone' => DhakaDate::zone(),
            ],
            /**
             * Advance payment a COD order of this value needs in this zone, or null.
             *
             * @var string|null
             */
            'cod_advance' => $quote->codAdvance?->toDecimal(),
        ];
    }

    private static function progress(DeliveryQuote $quote): ?int
    {
        $threshold = $quote->freeShippingThreshold;
        if ($threshold === null || ! $threshold->greaterThan(Money::zero())) {
            return null;
        }
        if ($quote->freeShipping) {
            return 100;
        }

        return min(100, (int) bcdiv(bcmul($quote->subtotal->amount, '100', 2), $threshold->amount, 0));
    }
}
