<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → completed: return window closed. */
final readonly class OrderCompleted extends OrderStatusChanged {}
