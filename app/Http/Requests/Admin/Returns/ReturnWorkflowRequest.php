<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Requests\ApiFormRequest;

/** `POST /returns/{orderReturn}/receive` — needs returns.manage. No body. */
final class ReturnWorkflowRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $return = $this->route('orderReturn');

        return $return instanceof OrderReturn && ($this->user()?->can('update', $return) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
