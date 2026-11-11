<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Fixtures;

use App\Http\Requests\ApiFormRequest;

/** Test-only request exercising the ApiFormRequest → validation-error problem path. */
final class StoreWidgetRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->header('X-Test-Deny') === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:20'],
            'qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
