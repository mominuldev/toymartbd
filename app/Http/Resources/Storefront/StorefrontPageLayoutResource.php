<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Content\Blocks\DTOs\ResolvedBlock;
use App\Domain\Content\Models\CmsPage;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A static page built from sections, for one locale at one instant: `page` (the StorefrontPage
 * document — title, SEO, legacy content) plus the homepage's block shape — `version`,
 * `published_at`, `preview`, `blocks` (StorefrontHomeResource). `version` is null and `blocks`
 * empty when the page has never been published from the builder; the storefront then renders
 * the page's legacy `content` / `faq_items`.
 *
 * @property array{page: CmsPage, version: int|null, published_at: string|null, preview: bool, locale: string, blocks: list<ResolvedBlock>} $resource
 */
#[SchemaName('StorefrontPageLayout')]
final class StorefrontPageLayoutResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $layout = (new StorefrontHomeResource([
            'version' => $this->resource['version'],
            'published_at' => $this->resource['published_at'],
            'preview' => $this->resource['preview'],
            'locale' => $this->resource['locale'],
            'blocks' => $this->resource['blocks'],
        ]))->resolve($request);

        return [
            /** @var array<string, mixed> */
            'page' => (new StorefrontPageResource($this->resource['page']))->resolve($request),
            /** @var int|null */
            'version' => $layout['version'],
            /** @var string|null */
            'published_at' => $layout['published_at'],
            /** @var bool */
            'preview' => $layout['preview'],
            /** @var list<array<string, mixed>> */
            'blocks' => $layout['blocks'],
        ];
    }
}
