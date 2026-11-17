<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Enums\AiProvider;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiDrivers;
use App\Domain\Ai\Services\AiSettings;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** "Check connection": a real call with the saved key, then the result stamped on the key's meta. */
final readonly class RecheckAiProvider
{
    public function __construct(private AiDrivers $drivers, private AiSettings $settings) {}

    /** @return bool whether the key works */
    public function handle(AiProvider $provider, ?int $userId): bool
    {
        $key = $this->settings->apiKey($provider) ?? throw new AiException(AiException::NOT_CONFIGURED, $provider->label().' is not connected.');
        $ok = true;
        try {
            $this->drivers->for($provider)->check($key, $provider->defaultModel());
        } catch (AiException $e) {
            if ($e->reason !== AiException::AUTH) {
                throw $e;
            }
            $ok = false;
        }
        $meta = $this->settings->meta($provider);
        DB::transaction(static fn () => Setting::put(AiSettings::metaName($provider), ['last4' => $meta['last4'], 'checked_at' => now()->toIso8601String(), 'ok' => $ok], AiSettings::group(), false, $userId));

        return $ok;
    }
}
