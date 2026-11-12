<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Domain\Notification\Actions\SyncDefaultTemplates;
use Illuminate\Console\Command;

/** Inserts any catalog notification template that has no row yet. Never overwrites an edit. */
final class SyncNotificationTemplates extends Command
{
    protected $signature = 'toymart:notifications:sync-templates';

    protected $description = 'Insert missing default notification templates (EN + BN), measured';

    public function handle(SyncDefaultTemplates $sync): int
    {
        $this->info("Inserted {$sync->handle()} template(s).");

        return self::SUCCESS;
    }
}
