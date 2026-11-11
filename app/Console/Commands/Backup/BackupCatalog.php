<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The backups on one disk, named `<prefix>-YYYYMMDD-HHMMSS.tar.gz.enc` (UTC), each
 * with a `<same>.manifest.json` beside it.
 */
final readonly class BackupCatalog
{
    public const string ARCHIVE_SUFFIX = '.tar.gz.enc';

    public const string MANIFEST_SUFFIX = '.manifest.json';

    public function __construct(
        private Filesystem $disk,
        private string $prefix,
    ) {}

    public function nameFor(CarbonImmutable $at): string
    {
        return $this->prefix.'-'.$at->utc()->format('Ymd-His');
    }

    public static function manifestPath(string $name): string
    {
        return $name.self::MANIFEST_SUFFIX;
    }

    public static function archivePath(string $name): string
    {
        return $name.self::ARCHIVE_SUFFIX;
    }

    /**
     * Every complete backup (archive AND manifest present), oldest first.
     *
     * @return array<string, CarbonImmutable> name => taken at (UTC)
     */
    public function all(): array
    {
        $files = $this->disk->files();
        $present = array_flip($files);
        $pattern = '/^'.preg_quote($this->prefix, '/').'-(\d{8}-\d{6})'.preg_quote(self::ARCHIVE_SUFFIX, '/').'$/';
        $backups = [];

        foreach ($files as $file) {
            if (preg_match($pattern, $file, $m) !== 1) {
                continue;
            }

            $name = substr($file, 0, -strlen(self::ARCHIVE_SUFFIX));

            if (! isset($present[self::manifestPath($name)])) {
                continue;
            }

            $backups[$name] = CarbonImmutable::createFromFormat('Ymd-His', $m[1], 'UTC') ?: CarbonImmutable::createFromTimestampUTC(0);
        }

        asort($backups);

        return $backups;
    }

    public function latest(): ?string
    {
        $all = $this->all();

        return $all === [] ? null : array_key_last($all);
    }

    /**
     * Which backups the retention policy drops. Pure, so it is unit-tested directly.
     * The newest backup is always kept. Days and months are Dhaka calendar days and
     * months (CLAUDE.md rule 11), so "one per day" means one per Dhaka day.
     *
     * @param  array<string, CarbonImmutable>  $backups  name => taken at
     * @param  array{keep_all_for_days: int, keep_daily_for_days: int, keep_monthly_for_months: int}  $policy
     * @return list<string>
     */
    public static function toPrune(array $backups, CarbonImmutable $now, array $policy, string $timezone = 'Asia/Dhaka'): array
    {
        // Newest first, so the first one seen in a day/month bucket is the one kept.
        arsort($backups);

        $now = $now->setTimezone($timezone);
        $keepAllAfter = $now->subDays($policy['keep_all_for_days']);
        $keepDailyAfter = $now->subDays($policy['keep_daily_for_days']);
        $keepMonthlyAfter = $now->subMonthsNoOverflow($policy['keep_monthly_for_months']);

        $seenDays = [];
        $seenMonths = [];
        $prune = [];
        $first = true;

        foreach ($backups as $name => $takenAt) {
            $local = $takenAt->setTimezone($timezone);
            $day = $local->format('Y-m-d');
            $month = $local->format('Y-m');

            $keep = $first
                || $local->greaterThanOrEqualTo($keepAllAfter)
                || ($local->greaterThanOrEqualTo($keepDailyAfter) && ! isset($seenDays[$day]))
                || ($local->greaterThanOrEqualTo($keepMonthlyAfter) && ! isset($seenMonths[$month]));

            $first = false;
            $seenDays[$day] = true;
            $seenMonths[$month] = true;

            if (! $keep) {
                $prune[] = (string) $name;
            }
        }

        return $prune;
    }

    /**
     * @param  array{keep_all_for_days: int, keep_daily_for_days: int, keep_monthly_for_months: int}  $policy
     * @return list<string> the names deleted
     */
    public function prune(CarbonImmutable $now, array $policy): array
    {
        $names = self::toPrune($this->all(), $now, $policy);

        foreach ($names as $name) {
            $this->disk->delete([self::archivePath($name), self::manifestPath($name)]);
        }

        return $names;
    }
}
