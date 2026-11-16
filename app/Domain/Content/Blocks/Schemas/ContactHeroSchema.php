<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;

/**
 * Contact hero (2026-11-16, Contact us 165:9891 / 167:7500): a Bloom-sky band with an overline
 * pill, the page headline, a lead and a status pill with a green dot ("Online now · WhatsApp
 * replies in about 15 minutes"), beside a chat illustration of up to four speech bubbles — each
 * from the customer (white, left) or the shop (brand, right), with a small label. Like the story
 * hero, placed first on a page built from sections it replaces the page header and its title is
 * the page's H1. The bubbles are decorative: screen readers skip them.
 */
final class ContactHeroSchema extends BlockSchema
{
    public const array FROM = ['customer', 'store'];

    public function rules(): array
    {
        return [
            ...self::text('overline', 40, false),
            ...self::text('title', 120),
            ...self::text('lead', 600),
            ...self::text('status', 120, false),
            'bubbles' => ['sometimes', 'array', 'max:4'],
            'bubbles.*' => ['required', 'array'],
            'bubbles.*.from' => ['required', Rule::in(self::FROM)],
            'bubbles.*.label' => ['nullable', 'string', 'max:24'],
            'bubbles.*.label_bn' => ['nullable', 'string', 'max:24'],
            'bubbles.*.text' => ['required', 'string', 'max:160'],
            'bubbles.*.text_bn' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function defaults(): array
    {
        return [
            'overline' => "We're here to help", 'overline_bn' => 'আমরা সাহায্য করতে আছি',
            'title' => 'Contact us', 'title_bn' => 'যোগাযোগ করুন',
            'lead' => 'Real people answer every message. Not sure which toy fits your child? Ask us.',
            'lead_bn' => 'প্রতিটি মেসেজের উত্তর দেন সত্যিকারের মানুষ। কোন খেলনা আপনার সন্তানের জন্য ঠিক, বুঝতে পারছেন না? আমাদের জিজ্ঞেস করুন।',
            'status' => null, 'status_bn' => null,
            'bubbles' => [],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'overline', 'title', 'lead', 'status');
        $out['bubbles'] = [];
        foreach (is_array($content['bubbles'] ?? null) ? array_slice($content['bubbles'], 0, 4) : [] as $bubble) {
            if (! is_array($bubble)) {
                continue;
            }
            $row = ['from' => ($bubble['from'] ?? null) === 'store' ? 'store' : 'customer'];
            self::copyText($bubble, $row, 'label', 'text');
            $out['bubbles'][] = $row;
        }

        return $out;
    }
}
