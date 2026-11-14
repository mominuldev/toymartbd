<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Fresh recovery codes — shown once, at creation. Only their hashes are stored.
 *
 * @property list<string> $resource
 */
final class RecoveryCodesResource extends ApiResource
{
    /** @return array{recovery_codes: list<string>} */
    public function toArray(Request $request): array
    {
        return [
            /** @var list<string> */
            'recovery_codes' => $this->resource,
        ];
    }
}
