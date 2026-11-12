<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

/** Where a customer first came from (DATABASE-DESIGN §3.3). */
enum CustomerSource: string
{
    case Web = 'web';
    case Facebook = 'facebook';
    case Phone = 'phone';
    case WalkIn = 'walk_in';
    case Whatsapp = 'whatsapp';
}
