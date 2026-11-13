<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Reporting\Enums\ReportFileFormat;
use App\Domain\Reporting\Enums\ReportFrequency;
use App\Domain\Reporting\Enums\ReportType;

final readonly class ReportScheduleData
{
    /** @param  list<int>  $recipientIds */
    public function __construct(
        public string $name,
        public ReportType $reportType,
        public ReportFrequency $frequency,
        public string $sendTime,
        public string $locale,
        public ReportFileFormat $format,
        public bool $isActive,
        public array $recipientIds,
    ) {}
}
