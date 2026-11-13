<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Http\Requests\ApiFormRequest;

/** `POST /inventory/adjustments/{adjustment}/approve` — needs inventory.approve_adjustments. Writes the adjustment's movements against the stock as it is now. */
final class ApproveStockAdjustmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', StockAdjustment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Why it was approved (optional). */
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
