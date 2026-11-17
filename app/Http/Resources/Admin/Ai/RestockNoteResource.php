<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The restock forecast's "In plain words" note. `omitted` says why there is none:
 * `figures_not_shared` (Integrations › AI assistant › sharing), `nothing_to_order`, or
 * `unverified_figures` (the AI quoted a number the forecast does not contain, twice).
 *
 * @property array{sentences: list<string>, model: string|null, generated_at: string|null, omitted: string|null} $resource
 */
#[SchemaName('RestockNote')]
final class RestockNoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /**
             * At most three sentences; empty when omitted.
             *
             * @var list<string>
             */
            'sentences' => $this->resource['sentences'],
            /**
             * The model id that wrote it.
             *
             * @var string|null
             */
            'model' => $this->resource['model'],
            /**
             * When it was written (UTC, ISO 8601).
             *
             * @var string|null
             */
            'generated_at' => $this->resource['generated_at'],
            /**
             * Why no note was written.
             *
             * @var 'figures_not_shared'|'nothing_to_order'|'unverified_figures'|null
             */
            'omitted' => $this->resource['omitted'],
        ];
    }
}
