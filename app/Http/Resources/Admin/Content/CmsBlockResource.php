<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Http\Resources\ApiResource;
use DateTimeInterface;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A draft block in the homepage builder. `content` is the type's stored JSON (both languages).
 * Instants are UTC ISO 8601; the builder shows them in Asia/Dhaka.
 *
 * @property CmsBlock $resource
 */
#[SchemaName('CmsBlock')]
final class CmsBlockResource extends ApiResource
{
    /**
     * @return array{id: int, uuid: string, type: string, label: string|null, content: array<string, mixed>, position: int, is_enabled: bool, show_en: bool, show_bn: bool, show_mobile: bool, show_desktop: bool, starts_at: string|null, ends_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $block = $this->resource;

        return [
            'id' => $block->id,
            'uuid' => $block->uuid,
            /** @var 'hero'|'category_tiles'|'age_tiles'|'product_rail'|'brand_strip'|'usp_band'|'promo_split'|'testimonials'|'blog_teaser'|'rich_text'|'faq' */
            'type' => $block->type->value,
            'label' => $block->label,
            /** @var array<string, mixed> */
            'content' => $block->content,
            'position' => $block->position,
            'is_enabled' => $block->is_enabled,
            'show_en' => $block->show_en,
            'show_bn' => $block->show_bn,
            'show_mobile' => $block->show_mobile,
            'show_desktop' => $block->show_desktop,
            /** @var string|null */
            'starts_at' => self::iso($block->starts_at),
            /** @var string|null */
            'ends_at' => self::iso($block->ends_at),
            /** @var string|null */
            'updated_at' => self::iso($block->updated_at),
        ];
    }

    public static function iso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
