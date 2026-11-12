<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → returned: fully returned (courier return or customer return). */
final readonly class OrderReturned extends OrderStatusChanged {}
