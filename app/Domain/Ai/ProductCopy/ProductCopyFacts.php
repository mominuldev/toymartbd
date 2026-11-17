<?php

declare(strict_types=1);

namespace App\Domain\Ai\ProductCopy;

use App\Domain\Ai\Services\Redactor;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductDescription;
use App\Support\Money;

/**
 * The facts Write with AI may use (Figma 538:82911) — read from the product itself and nothing
 * else: names, category, brand, age range, gender, specifications, variant options, warranty and
 * safety note as written, the current description as reference, and the selling price as a
 * display string. Never cost, SKU, barcode, stock, customers or orders.
 *
 * Each fact is `{key, value}`: `key` is stable (the admin labels it in its own language), `value`
 * is exactly what the model is shown.
 */
final readonly class ProductCopyFacts
{
    /** The reference description is cut here in the prompt; the dialog shows a shorter excerpt. */
    public const int DESCRIPTION_MAX = 1500;

    public const int EXCERPT_MAX = 140;

    /** @param  list<array{key: string, value: string}>  $items */
    private function __construct(public array $items) {}

    public static function of(Product $product): self
    {
        $product->loadMissing(['brand', 'primaryCategory', 'variants.attributeValues.attribute']);
        $items = [];
        $add = static function (string $key, ?string $value) use (&$items): void {
            // Redacted here too, so the facts the dialog lists are exactly what the provider sees.
            $value = $value === null ? '' : trim((string) preg_replace('/\s+/u', ' ', Redactor::clean($value)));
            if ($value !== '') {
                $items[] = ['key' => $key, 'value' => $value];
            }
        };

        $add('name', $product->name);
        $add('name_bn', $product->name_bn);
        $add('category', self::bilingual($product->primaryCategory->name, $product->primaryCategory->name_bn));
        $add('brand', $product->brand ? self::bilingual($product->brand->name, $product->brand->name_bn) : null);
        $add('age', self::age($product->age_min_months, $product->age_max_months));
        $add('gender', $product->gender?->value);
        $add('educational', $product->is_educational ? 'yes' : null);
        foreach ($product->specifications ?? [] as $label => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $label = (string) $label;
                $add('spec', ctype_digit($label) ? (string) $value : $label.': '.$value);
            }
        }
        foreach (self::options($product) as $line) {
            $add('option', $line);
        }
        $add('price', self::price($product));
        $add('warranty', $product->warranty_months !== null && $product->warranty_months > 0 ? $product->warranty_months.' months' : null);
        $add('safety_note', $product->safety_note);
        $add('safety_note_bn', $product->safety_note_bn);
        $add('description', self::cut(ProductDescription::plainText($product->description), self::DESCRIPTION_MAX));
        $add('description_bn', self::cut(ProductDescription::plainText($product->description_bn), self::DESCRIPTION_MAX));

        return new self($items);
    }

    /**
     * What the dialog lists: long reference text shortened to an excerpt.
     *
     * @return list<array{key: string, value: string}>
     */
    public function forDisplay(): array
    {
        return array_map(
            static fn (array $f): array => ['key' => $f['key'], 'value' => self::cut($f['value'], self::EXCERPT_MAX)],
            $this->items,
        );
    }

    /** One fact per line, for the user turn. */
    public function forPrompt(): string
    {
        return implode("\n", array_map(static fn (array $f): string => '- '.$f['key'].': '.$f['value'], $this->items));
    }

    /** @return list<string> every value, for {@see \App\Domain\Ai\Services\FigureGuard} */
    public function values(): array
    {
        return array_column($this->items, 'value');
    }

    private static function bilingual(string $en, ?string $bn): string
    {
        return $bn !== null && trim($bn) !== '' && $bn !== $en ? $en.' / '.$bn : $en;
    }

    private static function age(?int $min, ?int $max): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }
        if ($max === null) {
            return self::months((int) $min).' and up';
        }
        if ($min === null || $min === 0) {
            return 'up to '.self::months($max);
        }

        return self::months($min).' to '.self::months($max);
    }

    private static function months(int $m): string
    {
        if ($m >= 24 && $m % 12 === 0) {
            return ($m / 12).' years';
        }

        return $m.' months';
    }

    /**
     * "Colour: Red, Blue" per attribute across the active variants.
     *
     * @return list<string>
     */
    private static function options(Product $product): array
    {
        $byAttribute = [];
        foreach (self::active($product) as $variant) {
            foreach ($variant->attributeValues as $value) {
                $label = self::bilingual($value->attribute->name, $value->attribute->name_bn);
                $byAttribute[$label][$value->id] = self::bilingual($value->value, $value->value_bn);
            }
        }

        $lines = [];
        foreach ($byAttribute as $label => $values) {
            $lines[] = $label.': '.implode(', ', $values);
        }

        return $lines;
    }

    /** "৳1,250" or "৳1,250 to ৳1,800" across active variants — display only, Western digits. */
    private static function price(Product $product): ?string
    {
        $prices = array_map(static fn (ProductVariant $v): Money => $v->price, self::active($product));
        if ($prices === []) {
            return null;
        }
        usort($prices, static fn (Money $a, Money $b): int => $a->compareTo($b));
        $low = self::taka($prices[0]);
        $high = self::taka($prices[count($prices) - 1]);

        return $low === $high ? $low : $low.' to '.$high;
    }

    /** @return list<ProductVariant> */
    private static function active(Product $product): array
    {
        return array_values($product->variants->filter(static fn (ProductVariant $v): bool => $v->status === VariantStatus::Active)->all());
    }

    private static function taka(Money $m): string
    {
        $d = $m->toDecimal();

        return '৳'.(str_ends_with($d, '.00') ? number_format((float) $d) : number_format((float) $d, 2));
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
