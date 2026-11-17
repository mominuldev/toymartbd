<?php

declare(strict_types=1);

namespace App\Domain\Ai\Restock;

use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiSettings;
use App\Domain\Ai\Services\FigureGuard;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\Cache;

/**
 * The "In plain words" note on the restock forecast: at most three plain sentences on what to
 * order first and why, written by the AI from the forecast's own figures and product names only
 * (rule 1 — every number is the server's; {@see FigureGuard} rejects any other, one retry, then
 * the note is omitted).
 *
 * Cached for {@see self::TTL_HOURS} hours per (Dhaka date, horizon, branch scope, language),
 * with a fingerprint of the facts: once the forecast moves, the old note is no longer shown.
 * Cost figures are never sent — the cached note is shared by staff with and without
 * catalog.view_cost. With `ai.share.figures` off no note is written; with `ai.share.products`
 * off the model sees SKUs instead of product names.
 *
 * Never inside a transaction (rule 4): {@see AiClient::run()} makes an external HTTP call.
 */
final readonly class RestockNote
{
    public const int TTL_HOURS = 6;

    public const int MAX_SENTENCES = 3;

    /** How many forecast rows the model sees (the most urgent first). */
    public const int MAX_ROWS = 15;

    /** The forecast is store-wide (see RestockForecast); the cache key keeps a scope slot for branches. */
    public const string SCOPE = 'all';

    public const string FIGURES_NOT_SHARED = 'figures_not_shared';

    public const string NOTHING_TO_ORDER = 'nothing_to_order';

    public const string UNVERIFIED_FIGURES = 'unverified_figures';

    private const string SYSTEM = <<<'TXT'
        You write a short restock note for the owner of a toy shop in Bangladesh.
        The user turn is JSON: the restock forecast the shop's system calculated. Rows are the most urgent first.
        Write at most 3 short sentences in plain, friendly words: which products to order first and why
        (how soon they run out compared with how long the supplier takes, and how many to order).
        Use ONLY the figures and product names in the JSON, written exactly as given. Do not calculate new
        numbers, totals or percentages. Never mention costs or prices other than sales_at_risk. No greeting, no
        lists, no markdown. Write in the language named by "language" (en = English, bn = Bangla), always with
        Western digits (0-9). Answer as JSON: {"sentences": ["…", "…"]}.
        TXT;

    public function __construct(private AiClient $client, private AiSettings $settings) {}

    /**
     * Why no note can be written for this forecast, or null when one can.
     *
     * @param  array{rows: list<RestockRow>}  $forecast
     */
    public function blocked(array $forecast): ?string
    {
        if (! $this->settings->share()['figures']) {
            return self::FIGURES_NOT_SHARED;
        }

        return $forecast['rows'] === [] ? self::NOTHING_TO_ORDER : null;
    }

    /**
     * The cached note for exactly this forecast, if any. Never calls a provider.
     *
     * @param  array{horizon: int, today: string, rows: list<RestockRow>, running_out: int, before_arrival: int, sales_at_risk: \App\Support\Money}  $forecast
     * @return array{sentences: list<string>, model: string, generated_at: string}|null
     */
    public function cached(array $forecast, string $language): ?array
    {
        if ($this->blocked($forecast) !== null) {
            return null;
        }
        $hit = Cache::get(self::key($forecast, $language));
        if (! is_array($hit) || ($hit['fingerprint'] ?? null) !== self::fingerprint($this->facts($forecast, $language))) {
            return null;
        }

        /** @var array{sentences: list<string>, model: string, generated_at: string} */
        return ['sentences' => $hit['sentences'], 'model' => $hit['model'], 'generated_at' => $hit['generated_at']];
    }

    /**
     * The note for this forecast: the cached one, else a new one from the provider.
     *
     * @param  array{horizon: int, today: string, rows: list<RestockRow>, running_out: int, before_arrival: int, sales_at_risk: \App\Support\Money}  $forecast
     * @return array{sentences: list<string>, model: string|null, generated_at: string|null, omitted: string|null}
     *
     * @throws AiException not configured, over the cap, or the provider failed
     */
    public function write(array $forecast, string $language, ?int $userId, bool $fresh = false): array
    {
        $blocked = $this->blocked($forecast);
        if ($blocked !== null) {
            return self::omitted($blocked);
        }
        if (! $fresh && ($hit = $this->cached($forecast, $language)) !== null) {
            return $hit + ['omitted' => null];
        }

        $facts = $this->facts($forecast, $language);
        $prompt = new AiPrompt(self::SYSTEM, (string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'type' => 'object',
            'properties' => ['sentences' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['sentences'],
            'additionalProperties' => false,
        ], 'restock_note');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = $this->client->run(AiFeature::RestockNote, $prompt, $userId);
            $sentences = self::sentences($result->json);
            if ($sentences === []) {
                if ($attempt === 2) {
                    throw new AiException(AiException::INVALID_OUTPUT, 'The restock note came back empty.');
                }

                continue;
            }
            if (FigureGuard::unknown(implode(' ', $sentences), $facts) !== []) {
                continue;
            }

            $note = ['sentences' => $sentences, 'model' => $result->model, 'generated_at' => DhakaDate::instant()->toIso8601ZuluString()];
            Cache::put(self::key($forecast, $language), $note + ['fingerprint' => self::fingerprint($facts)], now()->addHours(self::TTL_HOURS));

            return $note + ['omitted' => null];
        }

        return self::omitted(self::UNVERIFIED_FIGURES);
    }

    /**
     * Exactly what the model sees — and what its figures are checked against. No cost figures.
     *
     * @param  array{horizon: int, rows: list<RestockRow>, running_out: int, before_arrival: int, sales_at_risk: \App\Support\Money}  $forecast
     * @return array<string, mixed>
     */
    public function facts(array $forecast, string $language): array
    {
        $names = $this->settings->share()['products'];

        return [
            'language' => $language,
            'horizon_days' => $forecast['horizon'],
            'running_out_within_horizon' => $forecast['running_out'],
            'running_out_before_new_stock_can_arrive' => $forecast['before_arrival'],
            'sales_at_risk_taka' => $forecast['sales_at_risk']->toDecimal(),
            'rows' => array_map(static fn (RestockRow $r): array => array_filter([
                'product' => $names ? $r->label($language) : null,
                'sku' => $r->sku,
                'in_stock' => $r->inStock,
                'sells_per_day' => $r->sellsPerDay,
                'runs_out_in_days' => $r->runsOutInDays,
                'supplier_lead_time_days' => $r->leadTimeDays,
                'lead_time_is_a_guess' => $r->leadTimeEstimated,
                'already_on_order' => $r->openPurchaseUnits,
                'suggested_order_units' => $r->suggestedOrder,
                'confidence' => $r->confidence,
            ], static fn (mixed $v): bool => $v !== null), array_slice($forecast['rows'], 0, self::MAX_ROWS)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return list<string>
     */
    private static function sentences(?array $json): array
    {
        $raw = is_array($json) && is_array($json['sentences'] ?? null) ? $json['sentences'] : [];
        $out = [];
        foreach ($raw as $s) {
            if (is_string($s) && trim($s) !== '') {
                $out[] = mb_substr(trim($s), 0, 400);
            }
        }

        return array_slice($out, 0, self::MAX_SENTENCES);
    }

    /** @return array{sentences: list<string>, model: null, generated_at: null, omitted: string} */
    private static function omitted(string $reason): array
    {
        return ['sentences' => [], 'model' => null, 'generated_at' => null, 'omitted' => $reason];
    }

    /** @param  array{horizon: int, today: string}  $forecast */
    private static function key(array $forecast, string $language): string
    {
        return 'ai:restock-note:'.$forecast['today'].':'.$forecast['horizon'].':'.self::SCOPE.':'.$language;
    }

    /** @param  array<string, mixed>  $facts */
    private static function fingerprint(array $facts): string
    {
        return hash('sha256', (string) json_encode($facts));
    }
}
