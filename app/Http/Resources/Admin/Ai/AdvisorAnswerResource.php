<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An Ask AI answer on the Growth advisor. `trimmed` is true when sentences that used a figure
 * not in the reports were removed (rule 1).
 *
 * @property array{answer_sentences: list<string>, sources: list<array{section: string, detail: string}>, model: string, trimmed: bool} $resource
 */
#[SchemaName('AdvisorAnswer')]
final class AdvisorAnswerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var list<string> */
            'answer_sentences' => $this->resource['answer_sentences'],
            /**
             * The report sections the answer used; `detail` is a short qualifier such as a weekday, or empty.
             *
             * @var list<array{section: 'sales'|'sales_by_day'|'channels'|'payment_methods'|'categories'|'products'|'stock'|'marketing'|'couriers'|'risk'|'catalog', detail: string}>
             */
            'sources' => $this->resource['sources'],
            /** The model id that wrote the answer, e.g. `claude-opus-5-5`. */
            'model' => $this->resource['model'],
            /**
             * True when sentences with a figure not in the reports were removed.
             *
             * @var bool
             */
            'trimmed' => $this->resource['trimmed'],
        ];
    }
}
