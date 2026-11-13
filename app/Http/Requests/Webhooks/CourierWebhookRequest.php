<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use App\Http\Requests\ApiFormRequest;

/**
 * A courier webhook (P6, `POST /api/v1/webhooks/couriers/{provider}`).
 *
 * Deliberately validates NOTHING in the body: rule 19 says verify the signature before
 * parsing anything, and each provider signs a different shape. The driver verifies the
 * raw bytes ({@see rawBody()}) first; only then does it parse them, and even the parsed
 * event is only a trigger to re-query the provider (rule 24c).
 */
final class CourierWebhookRequest extends ApiFormRequest
{
    /** Request headers kept on the receipt for a dispute — never Authorization or a signature. */
    private const array KEPT_HEADERS = ['user-agent', 'content-type', 'x-request-id'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** Exactly the bytes the provider signed. */
    public function rawBody(): string
    {
        return (string) $this->getContent();
    }

    /** @return array<string, list<string|null>> every header, lower-cased names */
    public function allHeaders(): array
    {
        /** @var array<string, list<string|null>> */
        return array_change_key_case($this->headers->all());
    }

    /** @return array<string, string|null> */
    public function keptHeaders(): array
    {
        $kept = [];
        foreach (self::KEPT_HEADERS as $name) {
            $kept[$name] = $this->header($name);
        }

        return $kept;
    }
}
