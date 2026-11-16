<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Numbered cards (2026-11-16, About us "What we believe"): a heading and one to six outlined
 * cards, each with a large number (01, 02…, set by position), a title and a sentence.
 */
final class NumberedCardsSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*' => ['required', 'array'],
            'items.*.title' => ['required', 'string', 'max:80'],
            'items.*.title_bn' => ['nullable', 'string', 'max:80'],
            'items.*.body' => ['nullable', 'string', 'max:300'],
            'items.*.body_bn' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'What we believe', 'title_bn' => 'আমরা যা বিশ্বাস করি',
            'items' => [['title' => 'Play is how children learn', 'title_bn' => 'খেলাই শিশুর শেখার পথ', 'body' => null, 'body_bn' => null]],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['items'] = [];
        foreach (is_array($content['items'] ?? null) ? $content['items'] : [] as $item) {
            if (is_array($item)) {
                $row = [];
                self::copyText($item, $row, 'title', 'body');
                $out['items'][] = $row;
            }
        }

        return $out;
    }
}
