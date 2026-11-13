<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/** FAQ: plain-text questions and answers in both languages; the storefront adds FAQPage JSON-LD. */
final class FaqSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('title', 120),
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*' => ['required', 'array'],
            'items.*.question' => ['required', 'string', 'max:200'],
            'items.*.question_bn' => ['nullable', 'string', 'max:200'],
            'items.*.answer' => ['required', 'string', 'max:2000'],
            'items.*.answer_bn' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Questions parents ask', 'title_bn' => 'অভিভাবকদের সাধারণ প্রশ্ন',
            'items' => [[
                'question' => 'Can I pay cash on delivery?', 'question_bn' => 'ক্যাশ অন ডেলিভারিতে টাকা দেওয়া যাবে?',
                'answer' => 'Yes. Pay the delivery person when the parcel reaches you, anywhere in Bangladesh.', 'answer_bn' => 'হ্যাঁ। পার্সেল হাতে পেয়ে ডেলিভারি কর্মীকে টাকা দিন, বাংলাদেশের যেকোনো জায়গায়।',
            ]],
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
                self::copyText($item, $row, 'question', 'answer');
                $out['items'][] = $row;
            }
        }

        return $out;
    }
}
