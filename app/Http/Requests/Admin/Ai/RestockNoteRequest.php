<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ai;

use App\Domain\Ai\Restock\RestockForecast;
use App\Domain\Inventory\Models\StockLevel;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** `POST /inventory/restock/note` — the AI note for the forecast at this horizon; needs inventory.view. */
final class RestockNoteRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', StockLevel::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Days ahead the forecast covers. */
            'horizon' => ['required', 'integer', Rule::in(RestockForecast::HORIZONS)],
            /** Write a new note even when today's note for this forecast is cached. */
            'fresh' => ['sometimes', 'boolean'],
        ];
    }

    public function horizon(): int
    {
        return $this->integer('horizon');
    }

    public function fresh(): bool
    {
        return $this->boolean('fresh');
    }
}
