<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Enums;

enum AlertState: string
{
    case Ok = 'ok';
    case Firing = 'firing';
}
