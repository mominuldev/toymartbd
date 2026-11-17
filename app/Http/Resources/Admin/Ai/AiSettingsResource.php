<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Integrations › AI assistant (Figma 536:82215). Keys never appear — only whether a provider is
 * connected, the key's last four characters and the last check.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('AiSettings')]
final class AiSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // `limited`: the provider accepted the key but answered "too many requests / quota" on the
        // last check — usually a free plan with no quota for the chosen model.
        return [
            /**
             * @var list<array{provider: 'anthropic'|'openai'|'gemini', label: string, connected: bool, last4: string|null, checked_at: string|null, check_ok: bool|null, limited: bool, default_model: string, models: list<array{id: string, label: string, input_usd_per_million: string, output_usd_per_million: string}>}>
             */
            'providers' => $this->resource['providers'],
            /**
             * @var list<array{feature: 'advisor'|'product_copy'|'inbox_reply'|'restock_note', provider: 'anthropic'|'openai'|'gemini', model: string, connected: bool}>
             */
            'features' => $this->resource['features'],
            /** Monthly cap, US dollars, e.g. `50.00`. */
            'cap_usd' => $this->resource['cap_usd'],
            /** @var array{figures: bool, products: bool, first_names: bool} */
            'share' => $this->resource['share'],
            /**
             * Estimated spend this Dhaka month (list prices × tokens); the provider's bill is the truth.
             *
             * @var array{spent_usd: string, requests: int, failed: int, by_feature: array<string, string>, since: string}
             */
            'usage' => $this->resource['usage'],
            /** @var array{update: bool, manage_keys: bool} */
            'can' => $this->resource['can'],
        ];
    }
}
