<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Jobs;

use App\Domain\Ai\Advisor\Actions\WriteWeeklyBrief;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The Saturday 09:00 Dhaka brief (routes/console.php). Does nothing when no AI key is set for the
 * advisor or report figures may not be shared. One attempt: a failed AI call is logged (the
 * reason only) and the owner can press "Refresh brief"; it is never retried into the cap.
 */
final class WriteScheduledBrief implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('reports');
    }

    public function handle(WriteWeeklyBrief $write, AiSettings $settings): void
    {
        ['provider' => $provider] = $settings->route(AiFeature::Advisor);
        if (! $settings->connected($provider) || ! $settings->share()['figures']) {
            return;
        }
        try {
            $write->handle(null, (string) config('app.locale', 'en'));
        } catch (AiException $e) {
            Log::warning('Growth advisor: scheduled brief not written.', ['reason' => $e->reason]);
        }
    }
}
