<?php

declare(strict_types=1);

namespace App\Domain\Notification\Data;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\TemplateKind;

/** A new staff-made template (P7 Message templates): one row per chosen channel. */
final readonly class CustomTemplateData
{
    /**
     * @param  list<NotificationChannel>  $channels
     * @param  list<string>  $variables
     */
    public function __construct(
        public string $nameEn,
        public string $nameBn,
        public TemplateKind $kind,
        public array $channels,
        public array $variables,
        public string $bodyEn,
        public string $bodyBn,
        public ?string $subjectEn = null,
        public ?string $subjectBn = null,
    ) {}
}
