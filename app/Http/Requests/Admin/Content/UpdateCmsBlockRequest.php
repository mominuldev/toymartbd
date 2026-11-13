<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Validator;

/**
 * `PATCH /content/home/blocks/{block}` — any subset of label, visibility, schedule and
 * `content`. `content` replaces the block's content whole and is validated against the block's
 * own type; the type cannot change.
 */
final class UpdateCmsBlockRequest extends ApiFormRequest
{
    use CmsBlockInput;

    public function authorize(): bool
    {
        $block = $this->route('block');

        return $block instanceof CmsBlock && ($this->user()?->can('update', $block) ?? false);
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkWindow($validator, $this->block()->starts_at, $this->block()->ends_at)];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->settingsRules(), ...($this->has('content') ? $this->contentRules($this->block()->type, false) : [])];
    }

    public function block(): CmsBlock
    {
        $block = $this->route('block');
        assert($block instanceof CmsBlock);

        return $block;
    }

    public function settings(): BlockSettings
    {
        return $this->settingsFor($this->block()->type);
    }
}
