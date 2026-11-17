<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Actions;

use App\Domain\Ai\Advisor\Exceptions\FiguresNotShared;
use App\Domain\Ai\Advisor\Services\AdvisorFacts;
use App\Domain\Ai\Advisor\Services\AdvisorPrompts;
use App\Domain\Ai\Advisor\Services\AdvisorWeek;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiSettings;

/**
 * Ask AI on the Growth advisor: one question answered from the same facts as the brief. Writes
 * nothing (the call is logged by AiClient). An answer that uses a figure not in the facts gets
 * one retry naming those figures; sentences still carrying one are removed and the answer is
 * flagged `trimmed` (rule 1).
 */
final readonly class AskAdvisor
{
    public function __construct(private AiClient $ai, private AdvisorFacts $facts, private AiSettings $settings) {}

    /**
     * @return array{answer_sentences: list<string>, sources: list<array{section: string, detail: string}>, model: string, trimmed: bool}
     */
    public function handle(string $question, string $locale, ?int $userId): array
    {
        if (! $this->settings->share()['figures']) {
            throw new FiguresNotShared;
        }
        $locale = $locale === 'bn' ? 'bn' : 'en';
        $facts = $this->facts->build(AdvisorWeek::current());

        $result = $this->ai->run(AiFeature::Advisor, AdvisorPrompts::ask($facts, $locale, $question), $userId);
        $answer = self::parse($result);
        $unknown = AdvisorPrompts::unknownFigures([...$answer['answer_sentences'], ...array_column($answer['sources'], 'detail')], $facts);
        if ($unknown !== []) {
            $result = $this->ai->run(AiFeature::Advisor, AdvisorPrompts::ask($facts, $locale, $question, AdvisorPrompts::retryNote($unknown)), $userId);
            $answer = self::parse($result);
        }

        $kept = array_values(array_filter($answer['answer_sentences'], static fn (string $s): bool => AdvisorPrompts::unknownFigures([$s], $facts) === []));
        $sources = array_map(
            static fn (array $s): array => AdvisorPrompts::unknownFigures([$s['detail']], $facts) === [] ? $s : ['section' => $s['section'], 'detail' => ''],
            $answer['sources'],
        );
        if ($kept === []) {
            throw new AiException(AiException::INVALID_OUTPUT, 'The AI answer used figures that are not in your reports. Try asking another way.');
        }

        return [
            'answer_sentences' => $kept,
            'sources' => $sources,
            'model' => $result->model,
            'trimmed' => count($kept) !== count($answer['answer_sentences']),
        ];
    }

    /** @return array{answer_sentences: list<string>, sources: list<array{section: string, detail: string}>} */
    private static function parse(AiResult $result): array
    {
        $json = $result->json;
        if (! is_array($json) || ! is_array($json['answer_sentences'] ?? null)) {
            throw new AiException(AiException::INVALID_OUTPUT, 'The AI answer was not in the expected shape. Try again.');
        }
        $sentences = [];
        foreach (array_slice($json['answer_sentences'], 0, 6) as $s) {
            $s = AdvisorPrompts::clean($s, 400);
            if ($s !== '') {
                $sentences[] = $s;
            }
        }
        $sources = [];
        $seen = [];
        foreach (is_array($json['sources'] ?? null) ? $json['sources'] : [] as $s) {
            if (! is_array($s) || ! in_array($s['section'] ?? null, AdvisorPrompts::SOURCES, true)) {
                continue;
            }
            $row = ['section' => (string) $s['section'], 'detail' => AdvisorPrompts::clean($s['detail'] ?? '', 40)];
            $key = $row['section'].'|'.$row['detail'];
            if (! isset($seen[$key]) && count($sources) < 4) {
                $seen[$key] = true;
                $sources[] = $row;
            }
        }

        return ['answer_sentences' => $sentences, 'sources' => $sources];
    }
}
