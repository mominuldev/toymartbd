<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → shipped: handed to the courier. */
final readonly class OrderShipped extends OrderStatusChanged {}
