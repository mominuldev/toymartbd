<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /returns/{orderReturn}/inspect` — needs returns.manage. One row per returned line:
 * how many units are resellable and how many damaged; they must add up to the line's
 * returned quantity (checked by InspectReturn).
 */
final class InspectReturnRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $return = $this->route('orderReturn');

        return $return instanceof OrderReturn && ($this->user()?->can('update', $return) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.resellable' => ['required', 'integer', 'min:0', 'max:10000'],
            'items.*.damaged' => ['required', 'integer', 'min:0', 'max:10000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<int, array{resellable: int, damaged: int}> */
    public function inspection(): array
    {
        $out = [];
        foreach ((array) $this->input('items', []) as $row) {
            if (is_array($row)) {
                $out[(int) $row['order_item_id']] = ['resellable' => (int) $row['resellable'], 'damaged' => (int) $row['damaged']];
            }
        }

        return $out;
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
