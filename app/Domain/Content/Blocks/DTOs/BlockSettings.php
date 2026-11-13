<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\DTOs;

use Carbon\CarbonImmutable;

/**
 * A block's editable fields, validated by the FormRequest. On update only the keys in `$present`
 * are written (PATCH semantics); `content` is always replaced whole and already normalised.
 * Instants are UTC (the request converts the admin's Dhaka wall-clock time).
 */
final readonly class BlockSettings
{
    /**
     * @param  array<string, mixed>|null  $content
     * @param  list<string>  $present  attribute names supplied by the request
     */
    public function __construct(
        public ?array $content = null,
        public ?string $label = null,
        public bool $isEnabled = true,
        public bool $showEn = true,
        public bool $showBn = true,
        public bool $showMobile = true,
        public bool $showDesktop = true,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public array $present = [],
    ) {}

    /** @return array<string, mixed> column → value for the supplied keys */
    public function attributes(): array
    {
        $all = [
            'content' => $this->content,
            'label' => $this->label,
            'is_enabled' => $this->isEnabled,
            'show_en' => $this->showEn,
            'show_bn' => $this->showBn,
            'show_mobile' => $this->showMobile,
            'show_desktop' => $this->showDesktop,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];

        return array_intersect_key($all, array_flip($this->present));
    }
}
