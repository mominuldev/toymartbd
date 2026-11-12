<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;

/**
 * Release from hold, start processing, mark ready to ship — needs orders.update. An
 * optional `note` lands on the order's timeline row with the move.
 */
class OrderWorkflowRequest extends ApiFormRequest
{
    public const int NOTE_MAX = 500;

    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('progress', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Shown on the order's activity timeline next to the change. */
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
