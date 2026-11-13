<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Content\DTOs\TestimonialData;
use App\Domain\Content\Models\Testimonial;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** `POST /content/testimonials` and `PUT /content/testimonials/{testimonial}` — the whole testimonial. */
final class TestimonialRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $testimonial = $this->route('testimonial');

        return $testimonial instanceof Testimonial
            ? ($this->user()?->can('update', $testimonial) ?? false)
            : ($this->user()?->can('create', Testimonial::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'name_bn' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:80'],
            'location_bn' => ['nullable', 'string', 'max:80'],
            'quote' => ['required', 'string', 'max:600'],
            'quote_bn' => ['nullable', 'string', 'max:600'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('kind', MediaKind::Image->value)],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): TestimonialData
    {
        $str = fn (string $key): ?string => is_string($this->input($key)) && trim($this->input($key)) !== '' ? trim($this->input($key)) : null;

        return new TestimonialData(
            name: (string) $str('name'),
            quote: (string) $str('quote'),
            nameBn: $str('name_bn'),
            location: $str('location'),
            locationBn: $str('location_bn'),
            quoteBn: $str('quote_bn'),
            rating: $this->filled('rating') ? $this->integer('rating') : null,
            mediaId: $this->filled('media_id') ? $this->integer('media_id') : null,
            isPublished: $this->boolean('is_published'),
        );
    }
}
