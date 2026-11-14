<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use JsonException;

/**
 * The restore-rehearsal record (P10 launch readiness): every run of
 * `toymart:backup:restore-rehearsal` writes `rehearsals/<UTC timestamp>.json` beside the
 * backups — on the same (off-host, in production) disk, so the evidence survives the
 * application server. `toymart:launch:check` reads the newest one.
 *
 * A record holds the backup name, the outcome, the reference used and the number of checks.
 * Never a secret, a row of data or a path on the host.
 */
final readonly class RehearsalLog
{
    public const string DIR = 'rehearsals';

    public function __construct(private Filesystem $disk) {}

    public function record(string $backup, bool $passed, string $against, int $checks, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now('UTC');

        $this->disk->put(self::DIR.'/'.$at->utc()->format('Ymd-His').'.json', (string) json_encode([
            'backup' => $backup,
            'passed' => $passed,
            'against' => $against,
            'checks' => $checks,
            'rehearsed_at' => $at->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The newest rehearsal record, or null when none was ever recorded.
     *
     * @return array{backup: string, passed: bool, against: string, checks: int, rehearsed_at: CarbonImmutable}|null
     */
    public function latest(): ?array
    {
        $files = array_values(array_filter(
            $this->disk->files(self::DIR),
            static fn (string $f): bool => preg_match('/\/\d{8}-\d{6}\.json$/', $f) === 1,
        ));
        sort($files);

        foreach (array_reverse($files) as $file) {
            try {
                $data = json_decode((string) $this->disk->get($file), true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($data) || ! is_string($data['backup'] ?? null) || ! is_string($data['rehearsed_at'] ?? null)) {
                continue;
            }

            return [
                'backup' => $data['backup'],
                'passed' => ($data['passed'] ?? false) === true,
                'against' => is_string($data['against'] ?? null) ? $data['against'] : 'source',
                'checks' => is_int($data['checks'] ?? null) ? $data['checks'] : 0,
                'rehearsed_at' => CarbonImmutable::parse($data['rehearsed_at']),
            ];
        }

        return null;
    }
}
