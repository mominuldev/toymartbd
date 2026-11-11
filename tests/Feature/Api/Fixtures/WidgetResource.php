<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Fixtures;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @property array{id: int, name: string} $resource
 */
final class WidgetResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'name' => $this->resource['name']];
    }
}
