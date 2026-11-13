<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Customers;

use App\Domain\Customer\Models\Customer;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A customer's date of birth after staff changed it (P8). Only returned to staff holding
 * customers.view_pii (the endpoint requires it), so the date is never masked here.
 *
 * @property Customer $resource
 */
#[SchemaName('CustomerDateOfBirth')]
final class CustomerDateOfBirthResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            /** @var string|null Y-m-d. */
            'date_of_birth' => $this->resource->date_of_birth?->format('Y-m-d'),
        ];
    }
}
