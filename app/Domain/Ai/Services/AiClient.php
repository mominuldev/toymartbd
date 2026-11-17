<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Models\AiRequest;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;

/**
 * The one way feature code calls an AI provider:
 *
 * 1. picks the provider and model set for the feature, and its key (else NOT_CONFIGURED);
 * 2. refuses once this Dhaka month's estimated spend reaches the cap (CAP_REACHED);
 * 3. redacts the user turn ({@see Redactor}) — feature code still sends only what it needs;
 * 4. calls the driver and writes one `ai_requests` row with tokens and estimated cost, whatever
 *    the outcome.
 *
 * Never inside a database transaction (rule 4): this makes an external HTTP call.
 */
final readonly class AiClient
{
    public function __construct(private AiSettings $settings, private AiDrivers $drivers) {}

    public function run(AiFeature $feature, AiPrompt $prompt, ?int $userId): AiResult
    {
        ['provider' => $provider, 'model' => $model] = $this->settings->route($feature);
        $key = $this->settings->apiKey($provider);
        $base = ['feature' => $feature->value, 'provider' => $provider->value, 'model' => $model, 'user_id' => $userId];

        if ($key === null) {
            throw new AiException(AiException::NOT_CONFIGURED, $provider->label().' is not connected.');
        }
        if (bccomp(self::spentThisMonth(), $this->settings->cap(), 6) >= 0) {
            AiRequest::query()->create($base + ['status' => AiRequest::BLOCKED, 'reason' => AiException::CAP_REACHED]);
            throw new AiException(AiException::CAP_REACHED);
        }

        $started = hrtime(true);
        try {
            $result = $this->drivers->for($provider)->complete($prompt->withUser(Redactor::clean($prompt->user)), $model, $key, $feature->maxTokens());
        } catch (AiException $e) {
            AiRequest::query()->create($base + ['status' => AiRequest::FAILED, 'reason' => $e->reason, 'latency_ms' => self::ms($started)]);
            throw $e;
        }

        AiRequest::query()->create($base + [
            'status' => AiRequest::OK,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => AiPricing::cost($provider, $model, $result->inputTokens, $result->outputTokens),
            'latency_ms' => self::ms($started),
        ]);

        return $result;
    }

    /** Estimated spend since the 1st of this month, Dhaka time (rule 11). */
    public static function spentThisMonth(): string
    {
        $sum = AiRequest::query()->where('created_at', '>=', self::monthStart())->sum('cost_usd');

        return number_format((float) $sum, 6, '.', '');
    }

    public static function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(DhakaDate::zone())->startOfMonth()->utc();
    }

    private static function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
