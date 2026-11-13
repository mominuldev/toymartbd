<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Models\Testimonial;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Validator;

/** `POST /content/testimonials/reorder` — `ids`: every testimonial id, once, in the new order. */
final class ReorderTestimonialsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Testimonial::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = $this->ids();
            sort($ids);
            $all = Testimonial::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            if ($ids !== $all) {
                $validator->errors()->add('ids', 'List every testimonial exactly once.');
            }
        }];
    }

    /** @return list<int> */
    public function ids(): array
    {
        $ids = $this->input('ids');

        return is_array($ids) ? array_values(array_map(static fn (mixed $id): int => (int) $id, $ids)) : [];
    }
}
