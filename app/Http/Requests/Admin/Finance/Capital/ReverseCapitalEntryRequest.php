<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance\Capital;

use App\Domain\Finance\Models\CapitalEntry;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /finance/capital/entries/{entry}/reverse` — `reason` (required, 3–500 characters; kept
 * as the reversal's note). Needs finance.manage_capital and an `Idempotency-Key`.
 */
final class ReverseCapitalEntryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof CapitalEntry && ($this->user()?->can('reverse', $entry) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
