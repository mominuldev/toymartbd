<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Enums;

enum TrackingSource: string
{
    case Browser = 'browser';
    case Server = 'server';
}
