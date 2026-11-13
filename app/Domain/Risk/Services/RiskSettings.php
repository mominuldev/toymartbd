<?php

declare(strict_types=1);

namespace App\Domain\Risk\Services;

use App\Domain\Risk\Enums\RiskFactor;
use App\Models\Setting;
use App\Support\Money;

/**
 * The owner's risk rules (admin "Risk rules" form): factor weights, level thresholds, the
 * first-order hold threshold (rule 33b) and two factor parameters. Stored as ONE settings
 * document, `risk.rules` (group `risk`); every key nobody has saved falls back to
 * config/risk.php. Read once per instance.
 *
 * PROVISIONAL: the owner has not set any of these (UI-UX-DESIGN-SPEC §10); defaults are §5.2's.
 */
final class RiskSettings
{
    public const string KEY = 'risk.rules';

    /** @var array<string, mixed>|null */
    private ?array $rules = null;

    /** @return array<string, int> factor code → weight, every factor present */
    public function weights(): array
    {
        $saved = $this->rules()['weights'] ?? [];
        $config = config('risk.weights', []);
        $weights = [];
        foreach (RiskFactor::cases() as $factor) {
            $value = is_array($saved) && array_key_exists($factor->value, $saved) ? $saved[$factor->value]
                : (is_array($config) && array_key_exists($factor->value, $config) ? $config[$factor->value] : $factor->defaultWeight());
            $weights[$factor->value] = is_numeric($value) ? max(0, min(100, (int) $value)) : $factor->defaultWeight();
        }

        return $weights;
    }

    public function weight(RiskFactor $factor): int
    {
        return $this->weights()[$factor->value];
    }

    public function mediumThreshold(): int
    {
        return $this->int('medium_threshold', config('risk.levels.medium'), 40);
    }

    public function highThreshold(): int
    {
        return $this->int('high_threshold', config('risk.levels.high'), 70);
    }

    public function firstOrderHoldThreshold(): Money
    {
        $value = $this->rules()['first_order_hold_threshold'] ?? config('risk.first_order_hold_threshold', '3000.00');

        return Money::of(is_string($value) || is_int($value) ? (string) $value : '3000.00');
    }

    /** `value_above_norm` fires above this multiple of the phone's average delivered order. */
    public function valueNormMultiplier(): string
    {
        $value = $this->rules()['value_norm_multiplier'] ?? config('risk.value_norm_multiplier', '2');

        return is_numeric($value) && (float) $value > 0 ? (string) $value : '2';
    }

    public function rapidRepeatMinutes(): int
    {
        return $this->int('rapid_repeat_minutes', config('risk.rapid_repeat_minutes'), 10);
    }

    /** `low` | `medium` | `high` for a score (§5.2 thresholds, inclusive lower bounds). */
    public function level(int $score): string
    {
        return match (true) {
            $score >= $this->highThreshold() => 'high',
            $score >= $this->mediumThreshold() => 'medium',
            default => 'low',
        };
    }

    /**
     * The whole document as the form reads it.
     *
     * @return array{weights: array<string, int>, medium_threshold: int, high_threshold: int, first_order_hold_threshold: string, value_norm_multiplier: string, rapid_repeat_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'weights' => $this->weights(),
            'medium_threshold' => $this->mediumThreshold(),
            'high_threshold' => $this->highThreshold(),
            'first_order_hold_threshold' => $this->firstOrderHoldThreshold()->toDecimal(),
            'value_norm_multiplier' => $this->valueNormMultiplier(),
            'rapid_repeat_minutes' => $this->rapidRepeatMinutes(),
        ];
    }

    /** Forget what was read (after a save). */
    public function flush(): void
    {
        $this->rules = null;
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        if ($this->rules === null) {
            $value = Setting::get(self::KEY);
            /** @var array<string, mixed> $rules */
            $rules = is_array($value) ? $value : [];
            $this->rules = $rules;
        }

        return $this->rules;
    }

    private function int(string $key, mixed $config, int $default): int
    {
        $value = $this->rules()[$key] ?? $config;

        return is_numeric($value) ? (int) $value : $default;
    }
}
