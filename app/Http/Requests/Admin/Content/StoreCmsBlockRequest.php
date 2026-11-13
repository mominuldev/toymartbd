<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /content/home/blocks` — add a block to the draft. `content` is optional: omitted, the
 * type's defaults are used; given, it is validated against the type's schema. `position` is the
 * 0-based slot (omitted = the end).
 */
final class StoreCmsBlockRequest extends ApiFormRequest
{
    use CmsBlockInput;

    public function authorize(): bool
    {
        return $this->user()?->can('create', CmsBlock::class) ?? false;
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkWindow($validator, null, null)];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->blockType();

        return [
            'type' => ['required', Rule::enum(BlockType::class)],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0'],
            ...$this->settingsRules(),
            ...($type === null || ! $this->has('content') ? [] : $this->contentRules($type, false)),
        ];
    }

    public function blockType(): ?BlockType
    {
        $type = $this->input('type');

        return is_string($type) ? BlockType::tryFrom($type) : null;
    }

    public function type(): BlockType
    {
        return BlockType::from($this->string('type')->toString());
    }

    public function position(): ?int
    {
        return $this->filled('position') ? $this->integer('position') : null;
    }

    public function settings(): BlockSettings
    {
        return $this->settingsFor($this->type());
    }
}
