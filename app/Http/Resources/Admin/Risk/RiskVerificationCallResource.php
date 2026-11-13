<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Risk\Models\RiskVerificationCall;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/** @property RiskVerificationCall $resource */
final class RiskVerificationCallResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $call = $this->resource;
        $name = $call->user_id !== null ? User::query()->whereKey($call->user_id)->value('name') : null;

        return [
            'id' => $call->id,
            'outcome' => $call->outcome->value,
            /** @var string|null */
            'note' => $call->note,
            /** @var string|null */
            'user_name' => is_string($name) ? $name : null,
            /** @var string|null */
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
