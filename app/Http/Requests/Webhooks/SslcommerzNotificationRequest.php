<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use App\Http\Requests\ApiFormRequest;

/**
 * An SSLCommerz IPN, or the browser-return POST forwarded by the storefront (P4-PAY-03).
 *
 * Validation here is shape only (bounded scalar strings) — it parses nothing into a domain
 * object and writes nothing. The signature is verified on the raw fields
 * ({@see raw()}) before anything else happens (rule 19); everything that moves money comes
 * from re-querying SSLCommerz, never from these fields.
 */
final class SslcommerzNotificationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $field = ['nullable', 'string', 'max:500'];

        return [
            'tran_id' => ['nullable', 'string', 'max:128'],
            'val_id' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'string', 'max:32'],
            'verify_sign' => ['nullable', 'string', 'max:128'],
            'verify_key' => $field,
            'amount' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'max:8'],
            'bank_tran_id' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * Every posted field exactly as received (the signature covers the raw strings).
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        /** @var array<string, mixed> */
        return $this->request->all();
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
