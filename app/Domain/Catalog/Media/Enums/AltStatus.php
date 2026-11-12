<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Enums;

/** The alt-text state a tile chip shows (A25 rule 1). Documents need no alt. */
enum AltStatus: string
{
    case Complete = 'complete';
    case MissingBn = 'missing_bn';
    case MissingEn = 'missing_en';
    case Missing = 'missing';
    case NotApplicable = 'not_applicable';
}
