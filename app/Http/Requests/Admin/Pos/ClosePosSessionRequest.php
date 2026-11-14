<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\Models\PosSession;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;

/** `POST /pos/sessions/{session}/close` — close with the counted cash. Own drawer with pos.use; anyone's with pos.manage. */
final class ClosePosSessionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof PosSession && ($this->user()?->can('close', $session) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The cash counted in the drawer, float included. */
            'counted_cash' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            /** Why the count is over or short, if it is. */
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function counted(): Money
    {
        return Money::of($this->string('counted_cash')->toString());
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
