<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Domain\Ai\ProductCopy\ProductCopyDraft;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Product › Write with AI (Figma 538:82911): a draft only — nothing is saved. A language not
 * asked for is null; a field not asked for is null.
 *
 * @property ProductCopyDraft $resource
 */
#[SchemaName('ProductCopyDraft')]
final class ProductCopyResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /**
             * English copy. `description_html` uses only p, ul, li and strong. `too_long` lists keys still over their limit (title 70, seo_title 60, meta_description 160); `unknown_figures` lists numbers that match no fact.
             *
             * @var array{title: string|null, description_html: string|null, seo_title: string|null, meta_description: string|null, too_long: list<'title'|'seo_title'|'meta_description'>, unknown_figures: list<string>}|null
             */
            'en' => $this->resource->en,
            /**
             * Bangla copy, Western digits; same shape as `en`.
             *
             * @var array{title: string|null, description_html: string|null, seo_title: string|null, meta_description: string|null, too_long: list<'title'|'seo_title'|'meta_description'>, unknown_figures: list<string>}|null
             */
            'bn' => $this->resource->bn,
            /** The model that wrote the draft, e.g. `claude-opus-5-5`. */
            'model' => $this->resource->model,
            /**
             * The facts the AI was given, from the product itself. `key` is stable; `value` is what the AI saw (long text shortened).
             *
             * @var list<array{key: 'name'|'name_bn'|'category'|'brand'|'age'|'gender'|'educational'|'spec'|'option'|'price'|'warranty'|'safety_note'|'safety_note_bn'|'description'|'description_bn', value: string}>
             */
            'facts' => $this->resource->facts,
        ];
    }
}
