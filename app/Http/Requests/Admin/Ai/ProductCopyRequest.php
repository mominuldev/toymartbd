<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ai;

use App\Domain\Ai\ProductCopy\ProductCopyWriter;
use App\Domain\Catalog\Models\Product;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** `POST /products/{product}/ai-copy` — what to write, in which language and tone; needs catalog.update. */
final class ProductCopyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && ($this->user()?->can('update', $product) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** What to write: the title, the description, and/or the SEO title with meta description. */
            'fields' => ['required', 'array', 'min:1', 'max:3'],
            'fields.*' => ['required', 'string', 'distinct', Rule::in(ProductCopyWriter::FIELDS)],
            /** `both` writes English and Bangla. */
            'language' => ['required', 'string', Rule::in(['en', 'bn', 'both'])],
            'tone' => ['required', 'string', Rule::in(ProductCopyWriter::TONES)],
            /** Search keywords to work in where they fit the facts. */
            'keywords' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return list<string> */
    public function fields(): array
    {
        /** @var list<string> $fields */
        $fields = array_values(array_unique((array) $this->validated('fields')));

        return $fields;
    }

    /** @return list<string> */
    public function languages(): array
    {
        $language = $this->string('language')->toString();

        return $language === 'both' ? ['en', 'bn'] : [$language];
    }

    public function tone(): string
    {
        return $this->string('tone')->toString();
    }

    public function keywords(): string
    {
        return $this->string('keywords')->squish()->toString();
    }
}
