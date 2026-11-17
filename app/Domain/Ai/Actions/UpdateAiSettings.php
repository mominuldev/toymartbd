<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Services\AiSettings;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Saves which provider and model each feature uses, the monthly cap and what may be shared. `updated_by` records who. */
final readonly class UpdateAiSettings
{
    /**
     * @param  array<string, array{provider: string, model: string}>  $features
     * @param  array{figures: bool, products: bool, first_names: bool}  $share
     */
    public function handle(array $features, string $capUsd, array $share, ?int $userId): void
    {
        DB::transaction(static function () use ($features, $capUsd, $share, $userId): void {
            Setting::put('ai.features', $features, AiSettings::group(), false, $userId);
            Setting::put('ai.cap_usd', number_format((float) $capUsd, 2, '.', ''), AiSettings::group(), false, $userId);
            Setting::put('ai.share', $share, AiSettings::group(), false, $userId);
        });
    }
}
