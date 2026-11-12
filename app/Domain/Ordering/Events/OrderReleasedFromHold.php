<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition on_hold → pending: verification done or the pause lifted; back in the queue. */
final readonly class OrderReleasedFromHold extends OrderStatusChanged {}
