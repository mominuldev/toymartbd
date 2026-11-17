<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Actions;

use App\Domain\Ai\Advisor\Enums\ActionKind;
use App\Domain\Ai\Advisor\Enums\ActionPriority;
use App\Domain\Ai\Advisor\Enums\ActionStatus;
use App\Domain\Ai\Advisor\Enums\ActionTarget;
use App\Domain\Ai\Advisor\Exceptions\FiguresNotShared;
use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Domain\Ai\Advisor\Services\AdvisorFacts;
use App\Domain\Ai\Advisor\Services\AdvisorPrompts;
use App\Domain\Ai\Advisor\Services\AdvisorWeek;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiSettings;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

/**
 * Writes (or rewrites) this week's Growth advisor brief and its suggested actions.
 *
 * 1. Facts from the Reporting queries ({@see AdvisorFacts}); refuses when report figures may
 *    not be shared ({@see FiguresNotShared}).
 * 2. One AI call — outside any transaction (rule 4). If the answer uses a figure that is not
 *    in the facts, one retry naming those figures; whatever is still unknown is dropped
 *    sentence by sentence and action by action (rule 1). Nothing left → `invalid_output`.
 * 3. Actions target only ids present in the facts; the link is built by the server.
 * 4. One transaction: the week's row is rewritten, its still-open actions replaced. Actions a
 *    person already marked done or not useful are kept.
 */
final readonly class WriteWeeklyBrief
{
    public function __construct(private AiClient $ai, private AdvisorFacts $facts, private AiSettings $settings) {}

    public function handle(?int $userId, string $locale = 'en'): AiBrief
    {
        if (! $this->settings->share()['figures']) {
            throw new FiguresNotShared;
        }
        $locale = $locale === 'bn' ? 'bn' : 'en';
        $week = AdvisorWeek::current();
        $facts = $this->facts->build($week);

        $result = $this->ai->run(AiFeature::Advisor, AdvisorPrompts::brief($facts, $locale), $userId);
        $answer = self::parse($result);
        $unknown = AdvisorPrompts::unknownFigures(self::texts($answer), $facts);
        if ($unknown !== []) {
            $result = $this->ai->run(AiFeature::Advisor, AdvisorPrompts::brief($facts, $locale, AdvisorPrompts::retryNote($unknown)), $userId);
            $answer = self::parse($result);
        }

        $sentences = array_values(array_filter($answer['sentences'], static fn (string $s): bool => AdvisorPrompts::unknownFigures([$s], $facts) === []));
        $actions = array_values(array_filter($answer['actions'], static fn (array $a): bool => AdvisorPrompts::unknownFigures([$a['title'], $a['why'], ...$a['figures']], $facts) === []));
        if ($sentences === []) {
            throw new AiException(AiException::INVALID_OUTPUT, 'The AI answer used figures that are not in your reports. Try again.');
        }
        $actions = self::target($actions, AdvisorFacts::targetIds($facts));
        ['provider' => $provider] = $this->settings->route(AiFeature::Advisor);

        return DB::transaction(static function () use ($week, $locale, $provider, $result, $sentences, $actions, $userId): AiBrief {
            $brief = AiBrief::query()->whereDate('week_start', $week->weekStart)->lockForUpdate()->first() ?? new AiBrief(['week_start' => $week->weekStart]);
            $brief->fill([
                'period_from' => $week->from,
                'period_to' => $week->to,
                'locale' => $locale,
                'provider' => $provider->value,
                'model' => $result->model,
                'sentences' => array_slice($sentences, 0, AdvisorPrompts::MAX_SENTENCES),
                'generated_by' => $userId,
                'generated_at' => DhakaDate::instant(),
            ])->save();

            $brief->actions()->where('status', ActionStatus::Open->value)->delete();
            foreach (array_slice($actions, 0, AdvisorPrompts::MAX_ACTIONS) as $i => $a) {
                $brief->actions()->create([...$a, 'position' => $i, 'status' => ActionStatus::Open->value]);
            }

            return $brief->refresh();
        });
    }

    /**
     * @return array{sentences: list<string>, actions: list<array{priority: string, kind: string, title: string, why: string, figures: list<string>, target_type: string, target_id: int|null}>}
     */
    private static function parse(AiResult $result): array
    {
        $json = $result->json;
        if (! is_array($json) || ! is_array($json['sentences'] ?? null) || ! is_array($json['actions'] ?? null)) {
            throw new AiException(AiException::INVALID_OUTPUT, 'The AI answer was not in the expected shape. Try again.');
        }
        $sentences = [];
        foreach ($json['sentences'] as $s) {
            $s = AdvisorPrompts::clean($s, 400);
            if ($s !== '') {
                $sentences[] = $s;
            }
        }
        $actions = [];
        foreach ($json['actions'] as $a) {
            if (! is_array($a)) {
                continue;
            }
            $title = AdvisorPrompts::clean($a['title'] ?? null, 160);
            $why = AdvisorPrompts::clean($a['why'] ?? null, 600);
            if ($title === '' || $why === '') {
                continue;
            }
            $figures = [];
            foreach (is_array($a['figures'] ?? null) ? array_slice($a['figures'], 0, 3) : [] as $f) {
                $f = AdvisorPrompts::clean($f, 40);
                if ($f !== '') {
                    $figures[] = $f;
                }
            }
            $actions[] = [
                'priority' => (ActionPriority::tryFrom((string) ($a['priority'] ?? '')) ?? ActionPriority::Medium)->value,
                'kind' => (ActionKind::tryFrom((string) ($a['kind'] ?? '')) ?? ActionKind::Other)->value,
                'title' => $title,
                'why' => $why,
                'figures' => $figures,
                'target_type' => (ActionTarget::tryFrom((string) ($a['target_type'] ?? '')) ?? ActionTarget::None)->value,
                'target_id' => is_int($a['target_id'] ?? null) ? $a['target_id'] : null,
            ];
        }

        return ['sentences' => $sentences, 'actions' => $actions];
    }

    /**
     * @param  array{sentences: list<string>, actions: list<array{title: string, why: string, figures: list<string>}>}  $answer
     * @return list<string>
     */
    private static function texts(array $answer): array
    {
        $texts = $answer['sentences'];
        foreach ($answer['actions'] as $a) {
            array_push($texts, $a['title'], $a['why'], ...$a['figures']);
        }

        return $texts;
    }

    /**
     * Keeps a target id only when the facts listed it; otherwise a product target loses its
     * link and a promotion target falls back to the promotions list.
     *
     * @param  list<array{priority: string, kind: string, title: string, why: string, figures: list<string>, target_type: string, target_id: int|null}>  $actions
     * @param  array{product: list<int>, promotion: list<int>}  $ids
     * @return list<array{priority: string, kind: string, title: string, why: string, figures: list<string>, target_type: string, target_id: int|null}>
     */
    private static function target(array $actions, array $ids): array
    {
        foreach ($actions as $i => $a) {
            $type = ActionTarget::from($a['target_type']);
            $known = match ($type) {
                ActionTarget::Product => $ids['product'],
                ActionTarget::Promotion => $ids['promotion'],
                default => [],
            };
            if (! $type->needsId() || ! in_array($a['target_id'], $known, true)) {
                $actions[$i]['target_id'] = null;
                if ($type === ActionTarget::Product) {
                    $actions[$i]['target_type'] = ActionTarget::None->value;
                }
            }
        }

        return $actions;
    }
}
