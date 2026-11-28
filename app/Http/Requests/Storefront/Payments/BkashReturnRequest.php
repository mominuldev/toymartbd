<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Payments;

use App\Domain\Payment\Actions\HandleBkashCallback;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * The customer's browser came back from bKash to our callbackURL, which bKash suffixed with
 * `paymentID`, `status` and `signature`; the storefront's route handler forwards those plus the
 * order number from the URL path. bKash publishes no way to verify `signature`, so these only
 * select which of OUR payments to re-query and whether the customer said success (which allows
 * the Execute call) — never the payment's status or amount (rule 19,
 * docs/_research/bkash-api.md §5).
 */
final class BkashReturnRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The order number from our callback URL's path. */
            'order_number' => ['required', 'string', 'regex:/^[A-Za-z0-9\-]{3,32}$/'],
            /** bKash's `paymentID` query parameter, forwarded untouched. */
            'payment_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9\-_]+$/'],
            /** bKash's `status` query parameter. */
            'status' => ['required', 'string', Rule::in(HandleBkashCallback::STATUSES)],
            /** bKash's `signature` query parameter — kept for disputes; there is no documented way to verify it. */
            'signature' => ['nullable', 'string', 'max:255'],
            /** Anything else bKash appended, kept for disputes only. */
            'query' => ['nullable', 'array', 'max:20'],
            'query.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function orderNumber(): string
    {
        return $this->string('order_number')->toString();
    }

    public function paymentId(): string
    {
        return $this->string('payment_id')->trim()->toString();
    }

    public function callbackStatus(): string
    {
        return $this->string('status')->toString();
    }

    /** @return array<string, mixed> */
    public function kept(): array
    {
        /** @var array<string, mixed> $query */
        $query = (array) $this->input('query', []);

        return ['order_number' => $this->orderNumber(), 'signature' => $this->input('signature')] + $query;
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
