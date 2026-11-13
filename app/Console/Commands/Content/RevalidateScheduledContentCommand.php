<?php

declare(strict_types=1);

namespace App\Console\Commands\Content;

use App\Domain\Content\Blocks\Services\ScheduleBoundarySweep;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Every minute: revalidate the storefront homepage when a scheduled block's start or end
 * (stored UTC, entered in Dhaka time) has just passed. Without it a block scheduled for
 * 00:00 Dhaka would wait for the ISR ceiling (an hour) to appear.
 */
final class RevalidateScheduledContentCommand extends Command
{
    protected $signature = 'toymart:content:revalidate-schedule';

    protected $description = 'Revalidate CMS pages whose scheduled blocks started or ended since the last run';

    public function handle(ScheduleBoundarySweep $sweep): int
    {
        $tags = $sweep->run(CarbonImmutable::now('UTC'));

        $this->line($tags === [] ? 'No schedule boundary passed.' : 'Revalidated: '.implode(', ', $tags));

        return self::SUCCESS;
    }
}
