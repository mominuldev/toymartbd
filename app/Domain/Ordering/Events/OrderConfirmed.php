<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → confirmed: staff (or the customer by phone) confirmed the order. */
final readonly class OrderConfirmed extends OrderStatusChanged {}
