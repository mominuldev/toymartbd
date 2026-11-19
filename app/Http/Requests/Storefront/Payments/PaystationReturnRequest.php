<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Payments;

use App\Http\Requests\ApiFormRequest;

/**
 * The customer's browser came back from PayStation to our callback_url; the storefront
 * forwards the order number from that URL and, if PayStation appended one, the invoice
 * number. PayStation documents neither the redirect's parameters nor any signature, so
 * these only select which of OUR payments to re-query — never its status or amount
 * (rule 19, docs/_research/paystation-api.md §6). Everything else PayStation appended
 * may be forwarded in `query` and is kept for disputes only.
 */
final class PaystationReturnRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'regex:/^[A-Za-z0-9\-]{3,32}$/'],
            'invoice_number' => ['nullable', 'string', 'max:128'],
            'query' => ['nullable', 'array', 'max:20'],
            'query.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function orderNumber(): string
    {
        return $this->string('order_number')->toString();
    }

    public function invoiceNumber(): ?string
    {
        return $this->filled('invoice_number') ? $this->string('invoice_number')->trim()->toString() : null;
    }

    /** @return array<string, mixed> */
    public function kept(): array
    {
        /** @var array<string, mixed> $query */
        $query = (array) $this->input('query', []);

        return ['order_number' => $this->orderNumber(), 'forwarded_invoice_number' => $this->invoiceNumber()] + $query;
    }

    /** @return array<string, string|null> */
    public function keptHeaders(): array
    {
        return [
            'user-agent' => $this->userAgent(),
            'content-type' => $this->header('Content-Type'),
            'x-request-id' => $this->header('X-Request-Id'),
        ];
    }
}
