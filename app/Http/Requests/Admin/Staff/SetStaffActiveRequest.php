<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;

/** `POST /staff/{user}/deactivate` and `/reactivate` — no body; needs staff.manage. */
final class SetStaffActiveRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('user');

        return $staff instanceof User && ($this->user()?->can('update', $staff) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
