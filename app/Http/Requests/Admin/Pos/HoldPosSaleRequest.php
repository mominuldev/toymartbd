<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

/** `POST /pos/sessions/{session}/holds` — park the cart ("Hold sale"). */
final class HoldPosSaleRequest extends PosCartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->cartRules(true),
            /** Optional name to find it again, e.g. `Blue shirt, coming back`. */
            'label' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function label(): ?string
    {
        $label = $this->input('label');

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }
}
