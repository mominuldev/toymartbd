<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

/**
 * `PUT /settings/locations/{location}` — edit a branch / warehouse, or switch it off
 * (`is_active: false`); needs settings.update. The whole record except `code`, which is
 * fixed once created.
 */
final class UpdateStockLocationRequest extends StockLocationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->sharedRules();
    }
}
