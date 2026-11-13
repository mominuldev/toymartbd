<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\Models\CourierSettlement;
use App\Http\Requests\ApiFormRequest;

/** `POST /finance/settlements/{settlement}/confirm` — no body. Needs finance.reconcile_cod and an `Idempotency-Key`. */
final class ConfirmSettlementRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconcile', CourierSettlement::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
