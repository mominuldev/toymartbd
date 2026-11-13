<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Invoicing;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /invoices/{invoice}/pdf` and `GET /purchases/{purchase}/invoice` — only the language.
 * Authorisation is per invoice (InvoicePolicy::print / printPurchase) in the controller,
 * after the model is bound.
 */
final class InvoicePdfRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'locale' => ['nullable', 'string', Rule::in(['en', 'bn'])],
        ];
    }

    public function locale(): ?string
    {
        $locale = $this->input('locale');

        return is_string($locale) ? $locale : null;
    }
}
