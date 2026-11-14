<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\Models\PosSession;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Illuminate\Validation\Rule;

/** `POST /pos/sessions` — open a drawer at a branch with its float. Needs pos.use. */
final class OpenPosSessionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PosSession::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The branch (stock location) the drawer sells from. Not a pickup point. */
            'location_id' => ['required', 'integer', Rule::exists('stock_locations', 'id')],
            /** e.g. `Register 1`. One open drawer per register per branch. */
            'register' => ['required', 'string', 'min:1', 'max:40'],
            /** Cash put in the drawer to give change from, e.g. `5000.00`. */
            'opening_float' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }

    public function openingFloat(): Money
    {
        return Money::of($this->string('opening_float')->toString());
    }
}
