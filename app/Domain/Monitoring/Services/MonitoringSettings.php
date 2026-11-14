<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Throwable;

/**
 * Monitoring thresholds: a `settings` row `monitoring.<path>` (group `system`) wins over
 * config/monitoring.php, so the owner can tune a threshold without a deploy. Loaded once per
 * instance (one query); a settings table that cannot be read falls back to config — the
 * alerts must still run when the database is the thing that is struggling.
 */
final class MonitoringSettings
{
    public const string PREFIX = 'monitoring.';

    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function get(string $path, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        return array_key_exists($path, $overrides) ? $overrides[$path] : config(self::PREFIX.$path, $default);
    }

    public function int(string $path, int $default = 0): int
    {
        $value = $this->get($path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return list<string> */
    public function list(string $path): array
    {
        $value = $this->get($path, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value)
            ? array_values(array_filter(array_map(static fn (mixed $v): string => trim(is_scalar($v) ? (string) $v : ''), $value), static fn (string $v): bool => $v !== ''))
            : [];
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $this->overrides = [];
        try {
            foreach (Setting::query()->where('group', SettingGroup::System->value)->where('key', 'like', self::PREFIX.'%')->get() as $setting) {
                $this->overrides[substr($setting->key, strlen(self::PREFIX))] = $setting->value;
            }
        } catch (Throwable) {
            // Config defaults only.
        }

        return $this->overrides;
    }
}
