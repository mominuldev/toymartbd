<?php

declare(strict_types=1);

namespace App\Domain\Ai\Enums;

/**
 * The AI providers a feature can use (owner decision 2026-11-17: Anthropic Claude, OpenAI and
 * Google Gemini, chosen per feature). Model ids and list prices are the providers' own, read
 * from their docs on 2026-11-17 (USD per 1M tokens, input / output) — {@see self::models()}.
 * A price is only used to estimate spend for the monthly cap; the provider's bill is the truth.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Gemini = 'gemini';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic Claude',
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
        };
    }

    /** Each provider's own recommended flagship (Anthropic: Claude Opus 5.5; OpenAI: "start with GPT-6 Astra"; Gemini: Gemini 4 Carbon). */
    public function defaultModel(): string
    {
        return match ($this) {
            self::Anthropic => 'claude-opus-5-5',
            self::OpenAi => 'gpt-6-astra',
            self::Gemini => 'gemini-4-carbon',
        };
    }

    /**
     * Selectable models: id => [label, input $/1M, output $/1M].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function models(): array
    {
        return match ($this) {
            self::Anthropic => [
                'claude-opus-5-5' => ['Claude Opus 5.5', '4.00', '20.00'],
                'claude-sonnet-5-5' => ['Claude Sonnet 5.5', '2.00', '10.00'],
                'claude-haiku-5-5' => ['Claude Haiku 5.5', '0.10', '0.50'],
            ],
            self::OpenAi => [
                'gpt-6-astra' => ['GPT-6 Astra', '10.00', '50.00'],
                'gpt-6.1-sol' => ['GPT-6.1 Sol', '2.00', '10.00'],
                'gpt-6.1-terra' => ['GPT-6.1 Terra', '1.00', '5.00'],
                'gpt-6-luna' => ['GPT-6 Luna', '0.10', '0.50'],
            ],
            // Gemini 4 Carbon / Argon list $2 / $10 until 2026-12-01, then $4 / $20 — the higher
            // price is used so the cap never under-counts.
            self::Gemini => [
                'gemini-4-carbon' => ['Gemini 4 Carbon', '4.00', '20.00'],
                'gemini-4-argon' => ['Gemini 4 Argon', '4.00', '20.00'],
                'gemini-4-hydrogen' => ['Gemini 4 Hydrogen', '1.50', '7.50'],
                'gemini-3.8-flash' => ['Gemini 3.8 Flash', '1.50', '7.50'],
                'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash-Lite', '0.30', '2.50'],
            ],
        };
    }

    public function hasModel(string $model): bool
    {
        return array_key_exists($model, $this->models());
    }
}
