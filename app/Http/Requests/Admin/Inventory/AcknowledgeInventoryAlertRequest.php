<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Domain\Inventory\Models\InventoryAlert;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /inventory/alerts/{alert}/acknowledge` — needs inventory.approve_adjustments.
 * `note` records what was found (a drift alert should say how it was resolved).
 */
final class AcknowledgeInventoryAlertRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('acknowledge', InventoryAlert::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** What was found and done. */
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
