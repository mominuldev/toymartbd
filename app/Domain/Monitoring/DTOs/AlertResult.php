<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\DTOs;

/**
 * One evaluation of one alert check. `summaryKey` + `params` render through the i18n layer
 * (lang/{en,bn}/monitoring.php). Params are counts, durations, provider codes and queue
 * names only — never a phone, address, e-mail or token (rule 20).
 */
final readonly class AlertResult
{
    /** @param  array<string, string|int|float>  $params */
    private function __construct(
        public bool $firing,
        public bool $applicable,
        public string $summaryKey,
        public array $params,
    ) {}

    /** @param  array<string, string|int|float>  $params */
    public static function firing(string $summaryKey, array $params = []): self
    {
        return new self(true, true, $summaryKey, $params);
    }

    /** @param  array<string, string|int|float>  $params */
    public static function ok(string $summaryKey = 'monitoring.summary.ok', array $params = []): self
    {
        return new self(false, true, $summaryKey, $params);
    }

    /** The check does not apply here (e.g. Horizon on a database queue, outside business hours). */
    public static function notApplicable(string $reason): self
    {
        return new self(false, false, 'monitoring.summary.not_applicable', ['reason' => $reason]);
    }

    public function summary(string $locale = 'en'): string
    {
        return (string) __($this->summaryKey, $this->params, $locale);
    }
}
