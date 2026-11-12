<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Media;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /media/bulk/delete` and `POST /media/bulk/download` — `{ ids }`, 1–200 distinct
 * integers. Both need media.delete (deleteAny). Delete reports unknown ids as
 * `not_found`; the zip refuses them (422 on `ids.N`).
 */
final class BulkMediaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('deleteAny', Media::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
        ];

        if ($this->routeIs('*.media.bulk.download')) {
            $rules['ids.*'][] = 'exists:media,id';
        }

        return $rules;
    }

    /** @return list<int> */
    public function ids(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->input('ids', []);

        return array_values(array_map(intval(...), $ids));
    }
}
