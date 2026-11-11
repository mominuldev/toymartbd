<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Brand;

use App\Domain\Catalog\DTOs\BrandData;
use App\Domain\Catalog\Models\Brand;
use App\Http\Requests\ApiFormRequest;
use App\Support\Slug;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * `POST /brands`. When `slug` is omitted it is generated from `name` (or `name_bn`),
 * ASCII-transliterated (rule 37) and made unique.
 */
final class StoreBrandRequest extends ApiFormRequest
{
    /** Lowercase ASCII kebab-case: "hot-wheels", "lego-2". */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Brand::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:'.Brand::SLUG_MAX, 'regex:'.self::SLUG_PATTERN, Rule::unique('brands', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_bn' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [self::slugCanBeGenerated(...)];
    }

    public function toData(): BrandData
    {
        $slug = $this->input('slug');

        return new BrandData(
            name: $this->string('name')->toString(),
            nameBn: self::nullableString($this->input('name_bn')),
            slug: is_string($slug) && $slug !== '' ? $slug : null,
            description: self::nullableString($this->input('description')),
            descriptionBn: self::nullableString($this->input('description_bn')),
            isActive: $this->has('is_active') ? $this->boolean('is_active') : true,
        );
    }

    /** A name of only punctuation or symbols transliterates to nothing; ask for a slug instead of failing later. */
    public static function slugCanBeGenerated(Validator $validator): void
    {
        $data = $validator->getData();

        if ($validator->errors()->hasAny(['name', 'name_bn', 'slug']) || (is_string($data['slug'] ?? null) && $data['slug'] !== '')) {
            return;
        }

        $name = $data['name'] ?? null;
        $nameBn = $data['name_bn'] ?? null;

        if (! is_string($name) && ! is_string($nameBn)) {
            return;
        }

        try {
            Slug::forNames(is_string($name) ? $name : null, is_string($nameBn) ? $nameBn : null);
        } catch (InvalidArgumentException) {
            $validator->errors()->add('slug', 'A URL slug cannot be generated from this name; supply a slug.');
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
