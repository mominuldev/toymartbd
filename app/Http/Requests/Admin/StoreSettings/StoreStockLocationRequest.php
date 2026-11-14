<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use Illuminate\Validation\Rule;

/** `POST /settings/locations` — add a branch, warehouse or pickup point; needs settings.update. */
final class StoreStockLocationRequest extends StockLocationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Short label the inventory screens show (`ctg` → "CTG 4"); lower-case letters, digits and dashes; fixed once created. */
            'code' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[a-z0-9-]+$/', Rule::unique('stock_locations', 'code')],
            ...$this->sharedRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        if (is_string($code)) {
            $this->merge(['code' => strtolower(trim($code))]);
        }
    }

    protected function code(): string
    {
        return $this->string('code')->toString();
    }
}
