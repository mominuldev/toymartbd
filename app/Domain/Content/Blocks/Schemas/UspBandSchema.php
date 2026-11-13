<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Content\Blocks\Enums\UspIcon;
use Illuminate\Validation\Rule;

/** "Why parents shop with us" band (Figma 134:960 / 127:444): eyebrow, title, two to four items. */
final class UspBandSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('eyebrow', 60, false),
            ...self::text('title', 120),
            'items' => ['required', 'array', 'min:2', 'max:4'],
            'items.*' => ['required', 'array'],
            'items.*.icon' => ['required', Rule::in(UspIcon::values())],
            'items.*.title' => ['required', 'string', 'max:60'],
            'items.*.title_bn' => ['nullable', 'string', 'max:60'],
            'items.*.detail' => ['nullable', 'string', 'max:160'],
            'items.*.detail_bn' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function defaults(): array
    {
        return [
            'eyebrow' => 'Our promise', 'eyebrow_bn' => 'আমাদের প্রতিশ্রুতি',
            'title' => 'Why parents shop with us', 'title_bn' => 'অভিভাবকেরা কেন আমাদের থেকে কেনেন',
            'items' => [
                ['icon' => 'cod', 'title' => 'Cash on delivery', 'title_bn' => 'ক্যাশ অন ডেলিভারি', 'detail' => 'Pay when it arrives — anywhere in Bangladesh', 'detail_bn' => 'হাতে পেয়ে টাকা দিন — বাংলাদেশের যেকোনো জায়গায়'],
                ['icon' => 'return', 'title' => '7-day easy return', 'title_bn' => '7 দিনে সহজ রিটার্ন', 'detail' => 'Unopened? Send it back, no questions', 'detail_bn' => 'বাক্স না খুললে ফেরত দিন, কোনো প্রশ্ন নেই'],
                ['icon' => 'authentic', 'title' => '100% authentic', 'title_bn' => '100% আসল', 'detail' => 'Genuine brands with full warranty', 'detail_bn' => 'আসল ব্র্যান্ড, পুরো ওয়ারেন্টি সহ'],
                ['icon' => 'support', 'title' => 'Real people, 9 to 9', 'title_bn' => 'সত্যিকারের মানুষ, সকাল 9টা–রাত 9টা', 'detail' => 'Call {phone} or WhatsApp us', 'detail_bn' => '{phone}-এ কল করুন বা হোয়াটসঅ্যাপে লিখুন'],
            ],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'eyebrow', 'title');
        $out['items'] = [];
        foreach (is_array($content['items'] ?? null) ? $content['items'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = ['icon' => UspIcon::tryFrom(is_string($item['icon'] ?? null) ? $item['icon'] : '')->value ?? UspIcon::Cod->value];
            self::copyText($item, $row, 'title', 'detail');
            $out['items'][] = $row;
        }

        return $out;
    }
}
