<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → processing: picking has started. */
final readonly class OrderProcessingStarted extends OrderStatusChanged {}
