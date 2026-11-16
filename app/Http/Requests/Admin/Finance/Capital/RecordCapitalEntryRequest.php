<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance\Capital;

use App\Domain\Finance\Enums\CapitalEntryKind;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Models\CapitalHolder;
use App\Http\Requests\Admin\Finance\ExpenseRequest;
use App\Http\Requests\ApiFormRequest;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /finance/capital/entries` — owner capital put in (`contribution`, "New investment")
 * or taken out (`drawing`): `kind`, `holder_id` (an active holder), `amount` (decimal string,
 * > 0), `occurred_on` (a Dhaka date, not in the future), `method` (the expense payment
 * methods), optional `reference` and `note`. Needs finance.manage_capital and an
 * `Idempotency-Key` (it moves money).
 */
final class RecordCapitalEntryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CapitalEntry::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CapitalEntryKind::class)],
            'holder_id' => ['required', 'integer', Rule::exists('capital_holders', 'id')],
            'amount' => ['required', 'string', 'regex:'.ExpenseRequest::MONEY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.DhakaDate::today()],
            'method' => ['required', Rule::enum(ExpensePaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('holder_id')) {
                return;
            }
            $holder = CapitalHolder::query()->find($this->input('holder_id'));
            if ($holder !== null && ! $holder->is_active) {
                $validator->errors()->add('holder_id', (string) __('capital.holder_inactive'));
            }
        }];
    }

    public function holder(): CapitalHolder
    {
        return CapitalHolder::query()->findOrFail((int) $this->validated('holder_id'));
    }

    public function kind(): CapitalEntryKind
    {
        return CapitalEntryKind::from((string) $this->validated('kind'));
    }

    public function amount(): Money
    {
        return Money::of((string) $this->validated('amount'));
    }

    public function occurredOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('occurred_on'), DhakaDate::zone()) ?? DhakaDate::todayDate();
    }

    public function paymentMethod(): ExpensePaymentMethod
    {
        return ExpensePaymentMethod::from((string) $this->validated('method'));
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
