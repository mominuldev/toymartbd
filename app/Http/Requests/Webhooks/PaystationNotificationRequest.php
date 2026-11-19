<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use App\Http\Requests\ApiFormRequest;

/**
 * A PayStation IPN (a JSON POST, sent only for successful transactions). PayStation
 * documents no signature, so validation here is shape only: it parses nothing into a
 * domain object, writes nothing, and nothing in it is ever trusted — the invoice number
 * only says which of OUR payments to re-query (rule 19, docs/_research/paystation-api.md §5).
 */
final class PaystationNotificationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:128'],
            'trx_id' => ['nullable', 'string', 'max:128'],
            'trx_status' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function invoiceNumber(): string
    {
        return $this->string('invoice_number')->trim()->toString();
    }

    /**
     * Every field exactly as received, for the dispute record.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        /** @var array<string, mixed> */
        return $this->all();
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
