<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared by the store and update block requests: the per-type content rules (the block type's
 * JSON schema, prefixed `content.`) and the settings fields, plus the conversion to BlockSettings.
 *
 * `starts_at` / `ends_at` take ISO 8601. Without an offset (`2026-06-15T00:00`, what a
 * datetime-local input sends) the time is read as Asia/Dhaka wall-clock time; stored UTC.
 *
 * @mixin FormRequest
 */
trait CmsBlockInput
{
    /** @return array<string, mixed> */
    protected function settingsRules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'show_en' => ['sometimes', 'boolean'],
            'show_bn' => ['sometimes', 'boolean'],
            'show_mobile' => ['sometimes', 'boolean'],
            'show_desktop' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * The window must end after it starts, comparing what will be stored: the request's value
     * where given, else the block's current one (a PATCH may send only `ends_at`).
     */
    protected function checkWindow(Validator $validator, ?CarbonImmutable $currentStart, ?CarbonImmutable $currentEnd): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        $start = $this->has('starts_at') ? self::dhakaInstant($this->input('starts_at')) : $currentStart;
        $end = $this->has('ends_at') ? self::dhakaInstant($this->input('ends_at')) : $currentEnd;

        if ($start !== null && $end !== null && ! $end->greaterThan($start)) {
            $validator->errors()->add('ends_at', __('validation.after', ['attribute' => 'ends at', 'date' => 'starts at']));
        }
    }

    /** @return array<string, mixed> */
    protected function contentRules(BlockType $type, bool $required): array
    {
        $rules = ['content' => [$required ? 'required' : 'sometimes', 'array']];

        foreach ($type->schema()->rules() as $key => $rule) {
            $rules['content.'.$key] = array_map(self::prefixReferences(...), is_array($rule) ? $rule : [$rule]);
        }

        return $rules;
    }

    /** Schema rules name sibling fields (`required_if:rule,category`); inside the request they live under `content.`. */
    private static function prefixReferences(mixed $rule): mixed
    {
        if (! is_string($rule) || preg_match('/^(required_if|required_unless|required_with|required_without|lte|gte|lt|gt|same|different):(.+)$/', $rule, $m) !== 1) {
            return $rule;
        }

        $args = explode(',', $m[2]);
        $prefixAll = in_array($m[1], ['required_with', 'required_without'], true);
        foreach ($args as $i => $arg) {
            if ($i === 0 || $prefixAll) {
                $args[$i] = 'content.'.$arg;
            }
        }

        return $m[1].':'.implode(',', $args);
    }

    protected function settingsFor(BlockType $type): BlockSettings
    {
        $present = [];
        foreach (['label', 'is_enabled', 'show_en', 'show_bn', 'show_mobile', 'show_desktop', 'starts_at', 'ends_at'] as $key) {
            if ($this->has($key)) {
                $present[] = $key;
            }
        }

        $content = null;
        if ($this->has('content')) {
            $present[] = 'content';
            /** @var array<string, mixed> $validated */
            $validated = $this->validated('content', []);
            $content = $type->schema()->normalize($validated);
        }

        $label = $this->input('label');

        return new BlockSettings(
            content: $content,
            label: is_string($label) && trim($label) !== '' ? trim($label) : null,
            isEnabled: $this->boolean('is_enabled', true),
            showEn: $this->boolean('show_en', true),
            showBn: $this->boolean('show_bn', true),
            showMobile: $this->boolean('show_mobile', true),
            showDesktop: $this->boolean('show_desktop', true),
            startsAt: self::dhakaInstant($this->input('starts_at')),
            endsAt: self::dhakaInstant($this->input('ends_at')),
            present: $present,
        );
    }

    public static function dhakaInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, DhakaDate::zone())->utc();
    }
}
