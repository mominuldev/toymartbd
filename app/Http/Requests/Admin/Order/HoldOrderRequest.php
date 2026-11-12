<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

/**
 * `POST /orders/{order}/hold` — needs orders.update. A hold always says why (`reason`),
 * so whoever picks the order up next knows what is being waited on.
 */
final class HoldOrderRequest extends OrderWorkflowRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Why the order is paused, e.g. "Customer asked to deliver after Friday". */
            'reason' => ['required', 'string', 'max:'.self::NOTE_MAX],
        ];
    }

    public function note(): string
    {
        return trim($this->string('reason')->toString());
    }
}
