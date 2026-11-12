<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/** Order transition → delivered: revenue is recognised here (§4.3), not at placement. */
final readonly class OrderDelivered extends OrderStatusChanged {}
