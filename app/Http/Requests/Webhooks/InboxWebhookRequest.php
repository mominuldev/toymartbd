<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use App\Http\Requests\ApiFormRequest;

/**
 * An inbox webhook (`POST /api/v1/webhooks/inbox/{channel}`). Validates NOTHING in the body:
 * rule 19 says verify the signature before parsing anything, so the driver checks
 * X-Hub-Signature-256 against {@see rawBody()} first.
 */
final class InboxWebhookRequest extends ApiFormRequest
{
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
}
