<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiProvider;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * Integrations › AI assistant (Figma 536:82215), stored in `settings` group `ai`:
 *
 * - `ai.key.{provider}` — the API key, `is_encrypted` (rule 20). Never returned by the API.
 * - `ai.key_meta.{provider}` — last four characters, when it was checked and whether it worked.
 * - `ai.features` — feature => {provider, model}. Default: Anthropic, Claude Opus 5.5.
 * - `ai.cap_usd` — the monthly spending cap (Dhaka month); `0` turns AI off.
 * - `ai.share` — what features may send: report figures, product details, customer first names.
 *   Phone numbers, addresses and payment details are never sent and have no switch.
 */
final class AiSettings
{
    /** Placeholder cap until the owner sets one (UI-UX-DESIGN-SPEC §10 placeholders). */
    public const string DEFAULT_CAP = '50.00';

    public const array SHARE_DEFAULTS = ['figures' => true, 'products' => true, 'first_names' => true];

    public static function keyName(AiProvider $p): string
    {
        return 'ai.key.'.$p->value;
    }

    public static function metaName(AiProvider $p): string
    {
        return 'ai.key_meta.'.$p->value;
    }

    public function apiKey(AiProvider $p): ?string
    {
        $value = Setting::get(self::keyName($p));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function connected(AiProvider $p): bool
    {
        return $this->apiKey($p) !== null;
    }

    /** @return array{last4: string|null, checked_at: string|null, ok: bool|null, limited: bool} */
    public function meta(AiProvider $p): array
    {
        $m = Setting::get(self::metaName($p));
        $m = is_array($m) ? $m : [];

        return [
            'last4' => is_string($m['last4'] ?? null) ? $m['last4'] : null,
            'checked_at' => is_string($m['checked_at'] ?? null) ? $m['checked_at'] : null,
            'ok' => is_bool($m['ok'] ?? null) ? $m['ok'] : null,
            'limited' => ($m['limited'] ?? false) === true,
        ];
    }

    /** @return array{provider: AiProvider, model: string} */
    public function route(AiFeature $f): array
    {
        $all = Setting::get('ai.features');
        $row = is_array($all) && is_array($all[$f->value] ?? null) ? $all[$f->value] : [];
        $provider = AiProvider::tryFrom((string) ($row['provider'] ?? '')) ?? AiProvider::Anthropic;
        $model = is_string($row['model'] ?? null) && $provider->hasModel($row['model']) ? $row['model'] : $provider->defaultModel();

        return ['provider' => $provider, 'model' => $model];
    }

    public function cap(): string
    {
        $v = Setting::get('ai.cap_usd');

        return is_string($v) && preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v) === 1 ? number_format((float) $v, 2, '.', '') : self::DEFAULT_CAP;
    }

    /** @return array{figures: bool, products: bool, first_names: bool} */
    public function share(): array
    {
        $v = Setting::get('ai.share');
        $out = self::SHARE_DEFAULTS;
        foreach (array_keys($out) as $k) {
            if (is_array($v) && is_bool($v[$k] ?? null)) {
                $out[$k] = $v[$k];
            }
        }

        return $out;
    }

    public static function group(): SettingGroup
    {
        return SettingGroup::Ai;
    }
}
