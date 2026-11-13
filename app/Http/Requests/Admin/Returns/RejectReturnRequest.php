<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Requests\ApiFormRequest;

/** `POST /returns/{orderReturn}/reject` — needs returns.manage; a reason is required. */
final class RejectReturnRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $return = $this->route('orderReturn');

        return $return instanceof OrderReturn && ($this->user()?->can('update', $return) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function note(): string
    {
        return trim($this->string('note')->toString());
    }
}
