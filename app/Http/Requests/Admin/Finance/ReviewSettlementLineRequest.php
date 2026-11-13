<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Models\CourierSettlement;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /finance/settlements/{settlement}/items/{item}/review` — `decision` (accepted|excluded;
 * accept is for a `short` line only) and a `note` (required to exclude: why it is not booked).
 * Needs finance.reconcile_cod.
 */
final class ReviewSettlementLineRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconcile', CourierSettlement::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([SettlementReview::Accepted->value, SettlementReview::Excluded->value])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:decision,excluded'],
        ];
    }

    public function decision(): SettlementReview
    {
        return SettlementReview::from((string) $this->validated('decision'));
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
