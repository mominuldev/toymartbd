<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Content\Blocks\Enums\TileIcon;
use Illuminate\Validation\Rule;

/**
 * Call-to-action band (2026-11-16, About us "Come and play at our shop"): a Bloom-gradient band
 * with a title, a line of copy and up to two buttons (primary, secondary), each with an optional
 * icon. A button without a label is not shown; a label needs a link (store path or https).
 */
final class CtaBandSchema extends BlockSchema
{
    public const array TONES = ['candy', 'sunrise', 'sky', 'mint'];

    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            ...self::text('body', 300, false),
            ...self::text('primary_label', 40, false),
            'primary_href' => [...self::href(), 'required_with:primary_label'],
            'primary_icon' => ['nullable', Rule::in(TileIcon::values())],
            ...self::text('secondary_label', 40, false),
            'secondary_href' => [...self::href(), 'required_with:secondary_label'],
            'secondary_icon' => ['nullable', Rule::in(TileIcon::values())],
            'tone' => ['sometimes', Rule::in(self::TONES)],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Come and see us', 'title_bn' => 'আমাদের সঙ্গে দেখা করুন',
            'body' => null, 'body_bn' => null,
            'primary_label' => 'Contact us', 'primary_label_bn' => 'যোগাযোগ করুন', 'primary_href' => '/contact', 'primary_icon' => 'chat',
            'secondary_label' => null, 'secondary_label_bn' => null, 'secondary_href' => null, 'secondary_icon' => null,
            'tone' => 'candy',
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title', 'body', 'primary_label', 'secondary_label');
        foreach (['primary', 'secondary'] as $button) {
            $out[$button.'_href'] = self::str($content[$button.'_href'] ?? null);
            $icon = $content[$button.'_icon'] ?? null;
            $out[$button.'_icon'] = is_string($icon) ? TileIcon::tryFrom($icon)?->value : null;
        }
        $tone = $content['tone'] ?? null;
        $out['tone'] = is_string($tone) && in_array($tone, self::TONES, true) ? $tone : 'candy';

        return $out;
    }
}
