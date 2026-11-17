<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The facts "Write with AI" would send for a product, listed before the first draft
 * (Figma 538:82911 "FACTS THE AI USES"). Same list the draft returns.
 *
 * @property list<array{key: string, value: string}> $resource
 */
#[SchemaName('ProductCopyFacts')]
final class ProductCopyFactsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var list<array{key: 'name'|'name_bn'|'category'|'brand'|'age'|'gender'|'educational'|'spec'|'option'|'price'|'warranty'|'safety_note'|'safety_note_bn'|'description'|'description_bn', value: string}>
             */
            'facts' => $this->resource,
        ];
    }
}
