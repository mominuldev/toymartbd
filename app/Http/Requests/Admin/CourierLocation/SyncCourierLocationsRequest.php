<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\CourierLocation;

use App\Domain\Fulfilment\Models\CourierLocation;
use App\Http\Requests\ApiFormRequest;

/** `POST /courier-locations/{provider}/sync` — queue a fresh pull; needs shipments.manage_locations. */
final class SyncCourierLocationsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sync', CourierLocation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
