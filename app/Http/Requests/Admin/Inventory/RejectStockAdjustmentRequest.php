<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Http\Requests\ApiFormRequest;

/** `POST /inventory/adjustments/{adjustment}/reject` — needs inventory.approve_adjustments. Writes no movement; the reason is required. */
final class RejectStockAdjustmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', StockAdjustment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Why it was rejected (required). */
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
