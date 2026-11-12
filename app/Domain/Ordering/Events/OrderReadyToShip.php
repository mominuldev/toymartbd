<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → ready_to_ship: packed; awaiting courier hand-over. */
final readonly class OrderReadyToShip extends OrderStatusChanged {}
