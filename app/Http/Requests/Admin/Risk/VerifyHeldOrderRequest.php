<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Risk\Models\RiskVerificationCall;
use App\Http\Requests\ApiFormRequest;

/** `POST /risk/orders/{order}/verify` — optional `note`. Needs risk.verify. */
final class VerifyHeldOrderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RiskVerificationCall::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
