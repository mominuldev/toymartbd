<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Content\Blocks\Enums\TileIcon;
use Illuminate\Validation\Rule;

/**
 * Feature tiles (2026-11-16, About us promise tiles): two to four pastel tiles, each a round icon
 * badge, a title and a sentence. The tile colours follow the position (mint, sky, butter,
 * lavender), so they never clash. `title` is optional: without one the tiles carry no heading.
 */
final class FeatureTilesSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120, false),
            'items' => ['required', 'array', 'min:2', 'max:4'],
            'items.*' => ['required', 'array'],
            'items.*.icon' => ['required', Rule::in(TileIcon::values())],
            'items.*.title' => ['required', 'string', 'max:60'],
            'items.*.title_bn' => ['nullable', 'string', 'max:60'],
            'items.*.body' => ['nullable', 'string', 'max:200'],
            'items.*.body_bn' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => null, 'title_bn' => null,
            'items' => [
                ['icon' => 'shield_check', 'title' => 'Age-checked toys', 'title_bn' => 'বয়স যাচাই করা খেলনা', 'body' => null, 'body_bn' => null],
                ['icon' => 'check_circle', 'title' => '7-day returns', 'title_bn' => '7 দিনে রিটার্ন', 'body' => null, 'body_bn' => null],
            ],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['items'] = [];
        foreach (is_array($content['items'] ?? null) ? $content['items'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = ['icon' => TileIcon::tryFrom(is_string($item['icon'] ?? null) ? $item['icon'] : '')->value ?? TileIcon::CheckCircle->value];
            self::copyText($item, $row, 'title', 'body');
            $out['items'][] = $row;
        }

        return $out;
    }
}
