<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/** Where an order came from (DATABASE-DESIGN §3.4). A phone order has the same shape as a web one. */
enum OrderSource: string
{
    case Web = 'web';
    case Admin = 'admin';
    case Phone = 'phone';
    case Facebook = 'facebook';
    case Whatsapp = 'whatsapp';
}
