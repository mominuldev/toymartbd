<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Staff & roles › Two-factor sign-in: the owner's switch and how many people it affects.
 *
 * @property array{required: bool, staff_without: int} $resource
 */
#[SchemaName('TwoFactorSetting')]
final class TwoFactorSettingResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** True: anyone who can see money must set up two-factor before using the admin. False (the default): it is optional. */
            'required' => (bool) $this->resource['required'],
            /** Active staff who can see money and have not set up two-factor — who would be held at the setup screen. */
            'staff_without' => (int) $this->resource['staff_without'],
        ];
    }
}
