<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

/**
 * `POST /pos/sessions/{session}/quote` — price the register cart and read the tenders.
 * Writes nothing. Every figure on the register comes from here (rule 1).
 */
final class QuotePosSaleRequest extends PosCartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->cartRules(false),
            ...$this->discountRules(),
            ...$this->tenderRules(false),
            /** The name the cashier typed for a new customer — a due tender needs one. */
            'customer_name' => ['nullable', 'string', 'max:160'],
        ];
    }
}
