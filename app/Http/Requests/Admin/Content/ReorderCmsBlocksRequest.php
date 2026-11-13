<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Http\Requests\ApiFormRequest;

/** `PUT /content/home/order` — `ids`: every draft block id, in the new order. */
final class ReorderCmsBlocksRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CmsBlock::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer', 'distinct']];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('ids')));
    }
}
