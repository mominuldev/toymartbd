<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → partially_returned: part of the order came back. */
final readonly class OrderPartiallyReturned extends OrderStatusChanged {}
