<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Analytics › Growth advisor (Figma 537:82561): the latest weekly brief, its open suggestions
 * and whether AI is ready for the advisor. Every sentence was checked against the facts it was
 * written from (rule 1).
 *
 * @property array{ai: array{configured: bool, figures_shared: bool, provider: string, model: string}, brief: AiBrief|null, actions: list<AiBriefAction>, open_count: int, done_this_month: int} $resource
 */
#[SchemaName('GrowthAdvisor')]
final class GrowthAdvisorResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $brief = $this->resource['brief'];

        return [
            /**
             * `configured`: the advisor's provider has a key. `figures_shared`: report figures may be sent (the advisor refuses without them).
             *
             * @var array{configured: bool, figures_shared: bool, provider: 'anthropic'|'openai'|'gemini', model: string}
             */
            'ai' => $this->resource['ai'],
            /**
             * Null until the first brief is written. `from`/`to` are the Dhaka dates covered (Monday to Sunday, or this week to date).
             *
             * @var array{id: int, week_start: string, from: string, to: string, locale: 'en'|'bn', provider: string, model: string, sentences: list<string>, generated_at: string, scheduled: bool}|null
             */
            'brief' => $brief === null ? null : [
                'id' => $brief->id,
                'week_start' => $brief->week_start->toDateString(),
                'from' => $brief->period_from->toDateString(),
                'to' => $brief->period_to->toDateString(),
                'locale' => $brief->locale,
                'provider' => $brief->provider,
                'model' => $brief->model,
                'sentences' => $brief->sentences,
                'generated_at' => $brief->generated_at->toIso8601String(),
                'scheduled' => $brief->generated_by === null,
            ],
            /** @var list<AdvisorActionResource> */
            'actions' => AdvisorActionResource::collection($this->resource['actions'])->resolve($request),
            /**
             * Open suggestions under the latest brief.
             *
             * @var int
             */
            'open_count' => $this->resource['open_count'],
            /**
             * Suggestions marked done since the 1st of this Dhaka month.
             *
             * @var int
             */
            'done_this_month' => $this->resource['done_this_month'],
        ];
    }
}
