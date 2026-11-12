<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\Models\ManualCodReceipt;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * `POST /finance/orders/{order}/cod-receipts` — cash received from the courier for one
 * delivered COD order: `amount` (> 0, ≤ outstanding), `received_on` (a Dhaka date, not in
 * the future), optional `reference` (the courier's payment id) and `note`. Needs
 * finance.reconcile_cod and an `Idempotency-Key`.
 */
final class RecordCodReceiptRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ManualCodReceipt::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:'.ExpenseRequest::MONEY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before:'.CarbonImmutable::now('Asia/Dhaka')->addDay()->toDateString()],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function amount(): Money
    {
        return Money::of((string) $this->validated('amount'));
    }

    public function receivedOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('received_on'), 'Asia/Dhaka') ?? CarbonImmutable::now('Asia/Dhaka');
    }

    public function reference(): ?string
    {
        $v = $this->validated('reference');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
