<?php

declare(strict_types=1);

namespace App\Domain\Seo\Enums;

enum TwitterCard: string
{
    case SummaryLargeImage = 'summary_large_image';
    case Summary = 'summary';
}
