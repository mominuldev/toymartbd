<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\DTOs\SaleData;
use App\Http\Middleware\EnsureIdempotency;
use App\Support\Money;

/**
 * `POST /pos/sessions/{session}/sales` — Charge. Requires an `Idempotency-Key` (rule 17).
 * `expected_total` is the total the cashier saw; a different server total is `price-changed`.
 */
final class ChargePosSaleRequest extends PosCartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->cartRules(true),
            ...$this->tenderRules(true),
            /** The quote's `grand_total`, e.g. `3455.00`. */
            'expected_total' => ['required', 'string', 'regex:'.self::MONEY],
            /** Printed on the receipt for a new customer; an existing customer keeps their name (a "Walk-in customer" placeholder is replaced). A due tender needs a name. */
            'customer_name' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function toData(): SaleData
    {
        $key = $this->header(EnsureIdempotency::HEADER);

        return new SaleData(
            sessionId: $this->drawer()->id,
            lines: $this->lines(),
            tenders: $this->tenders() ?? [],
            expectedTotal: Money::of($this->string('expected_total')->toString()),
            cashierId: (int) $this->user()?->getAuthIdentifier(),
            couponCode: $this->couponCode(),
            phone: $this->phone(),
            customerName: $this->customerName(),
            idempotencyKey: is_string($key) && $key !== '' ? $key : null,
            canSellOnDue: $this->canSellOnDue(),
        );
    }
}
