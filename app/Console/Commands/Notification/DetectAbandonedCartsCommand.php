<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Actions\DetectAbandonedCarts;
use Illuminate\Console\Command;

/** Abandoned-cart detection (P7): one reminder per abandoned cart, with consent. Scheduled every 10 minutes. */
final class DetectAbandonedCartsCommand extends Command
{
    protected $signature = 'toymart:carts:recover';

    protected $description = 'Detect abandoned carts and queue one SMS reminder each (consent and limits permitting)';

    public function handle(DetectAbandonedCarts $detect): int
    {
        $tally = $detect->handle();
        $this->info("Messaged {$tally['messaged']}, skipped {$tally['skipped']}.");

        return self::SUCCESS;
    }
}
