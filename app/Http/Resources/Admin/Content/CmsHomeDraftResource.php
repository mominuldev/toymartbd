<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Content;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The homepage builder's state: the draft blocks in order, the live version (null before the
 * first publish), whether the draft differs from live, the block types the library offers with
 * their default content, and a thumbnail for every media id the draft references.
 *
 * @property array{blocks: Collection<int, CmsBlock>, live: CmsLayoutVersion|null, has_unpublished_changes: bool} $resource
 */
#[SchemaName('CmsHomeDraft')]
final class CmsHomeDraftResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $blocks = $this->resource['blocks'];
        $live = $this->resource['live'];

        return [
            'placement' => 'home',
            /** @var list<array<string, mixed>> */
            'blocks' => CmsBlockResource::collection($blocks)->resolve($request),
            /** @var array<string, mixed>|null */
            'live' => $live === null ? null : (new CmsLayoutVersionResource($live, $live->version))->resolve($request),
            /** @var bool */
            'has_unpublished_changes' => $this->resource['has_unpublished_changes'],
            /** @var list<array{type: string, defaults: array<string, mixed>}> */
            'block_types' => array_map(static fn (BlockType $type): array => ['type' => $type->value, 'defaults' => $type->schema()->normalize($type->schema()->defaults())], BlockType::cases()),
            /** @var list<array{id: int, thumb_url: string|null, alt_text: string|null, alt_text_bn: string|null}> */
            'media' => self::media($blocks),
        ];
    }

    /**
     * @param  Collection<int, CmsBlock>  $blocks
     * @return list<array{id: int, thumb_url: string|null, alt_text: string|null, alt_text_bn: string|null}>
     */
    private static function media(Collection $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            $content = $block->content;
            if (is_numeric($content['media_id'] ?? null)) {
                $ids[] = (int) $content['media_id'];
            }
            foreach (is_array($content['slides'] ?? null) ? $content['slides'] : [] as $slide) {
                if (is_array($slide) && is_numeric($slide['media_id'] ?? null)) {
                    $ids[] = (int) $slide['media_id'];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return array_values(Media::query()->whereIn('id', array_unique($ids))->get()->map(static fn (Media $media): array => [
            'id' => $media->id,
            'thumb_url' => $media->url(320),
            'alt_text' => $media->alt_text,
            'alt_text_bn' => $media->alt_text_bn,
        ])->all());
    }
}
