<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/** Testimonials slot: the first `limit` published testimonials in their own order. Hidden when there are none. */
final class TestimonialsSchema extends BlockSchema
{
    public function rules(): array
    {
        return [...self::text('title', 120), 'limit' => ['sometimes', 'integer', 'min:1', 'max:12']];
    }

    public function defaults(): array
    {
        return ['title' => 'What parents say', 'title_bn' => 'অভিভাবকেরা যা বলেন', 'limit' => 6];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'title');
        $out['limit'] = self::int($content['limit'] ?? null, 6);

        return $out;
    }
}
