<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Services;

use App\Domain\Ai\Advisor\Enums\ActionStatus;
use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiSettings;

/**
 * What the Growth advisor screen shows: whether AI is set up for it, the latest brief, that
 * brief's open suggestions and how many suggestions were marked done this Dhaka month.
 */
final readonly class AdvisorOverview
{
    public function __construct(private AiSettings $settings) {}

    /**
     * @return array{ai: array{configured: bool, figures_shared: bool, provider: string, model: string}, brief: AiBrief|null, actions: list<AiBriefAction>, open_count: int, done_this_month: int}
     */
    public function build(): array
    {
        ['provider' => $provider, 'model' => $model] = $this->settings->route(AiFeature::Advisor);
        $brief = AiBrief::query()->orderByDesc('week_start')->first();
        $actions = $brief === null ? [] : $brief->actions()->where('status', ActionStatus::Open->value)->orderBy('position')->orderBy('id')->get()->all();

        return [
            'ai' => [
                'configured' => $this->settings->connected($provider),
                'figures_shared' => $this->settings->share()['figures'],
                'provider' => $provider->value,
                'model' => $model,
            ],
            'brief' => $brief,
            'actions' => array_values($actions),
            'open_count' => count($actions),
            'done_this_month' => AiBriefAction::query()
                ->where('status', ActionStatus::Done->value)
                ->where('decided_at', '>=', AiClient::monthStart())
                ->count(),
        ];
    }
}
