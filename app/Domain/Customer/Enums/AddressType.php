<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

enum AddressType: string
{
    case Home = 'home';
    case Office = 'office';
    case Other = 'other';
}
