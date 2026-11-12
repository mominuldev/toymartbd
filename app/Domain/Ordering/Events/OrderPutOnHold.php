<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → on_hold: waiting on human verification or a staff decision (never auto-cancelled, rule 24b). */
final readonly class OrderPutOnHold extends OrderStatusChanged {}
