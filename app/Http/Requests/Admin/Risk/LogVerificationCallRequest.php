<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Risk\Enums\CallOutcome;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** `POST /risk/orders/{order}/calls` — `outcome` (CallOutcome) and an optional `note`. Needs risk.verify. */
final class LogVerificationCallRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RiskVerificationCall::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(CallOutcome::values())],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function outcome(): CallOutcome
    {
        return CallOutcome::from((string) $this->validated('outcome'));
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
