<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Purchasing;

use App\Domain\Purchasing\Models\Purchase;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /purchases/{purchase}/close` — close a partly received PO short; needs
 * purchasing.create (like cancelling) and a reason. 409 unless partially received.
 */
final class ClosePurchaseRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $purchase = $this->route('purchase');

        return $purchase instanceof Purchase && ($this->user()?->can('close', $purchase) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Why the rest will not arrive (supplier out of stock, discontinued …). */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
