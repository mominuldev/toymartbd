<?php

declare(strict_types=1);

namespace App\Console\Commands\Launch;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * php artisan toymart:launch:check — Gate 5 / Phase 10 launch readiness (docs/ops/LAUNCH-CHECKLIST.md).
 *
 * Runs every automatable launch-checklist item read-only and lists the manual ones with how to
 * verify them. Exit 1 when any check FAILs (with `--strict`, also on a WARN), so a deploy
 * pipeline or the owner can gate on it. `--json` prints a machine-readable report; `--only`
 * narrows to ids or prefixes (`--only=backups --only=seo.robots`). No secret value, phone
 * number or address is ever printed.
 */
final class LaunchCheckCommand extends Command
{
    protected $signature = 'toymart:launch:check
        {--json : Print the report as JSON}
        {--only=* : Run only these check ids or id prefixes (e.g. backups, seo.robots)}
        {--strict : Treat warnings as failures}
        {--no-manual : Leave the manual items out of the report}';

    protected $description = 'Verify every automatable launch-checklist item; exit non-zero on a failure';

    public function handle(LaunchChecks $checks): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only'), 'is_string'));
        $results = $checks->run($only);
        if ($this->option('no-manual') === true) {
            $results = array_values(array_filter($results, static fn (CheckResult $r): bool => $r->status !== CheckStatus::Manual));
        }

        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'manual' => 0];
        foreach ($results as $result) {
            $counts[$result->status->value]++;
        }
        $failed = $counts['fail'] > 0 || ($this->option('strict') === true && $counts['warn'] > 0);

        if ($this->option('json') === true) {
            $this->line((string) json_encode([
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'environment' => (string) app()->environment(),
                'ready' => ! $failed,
                'summary' => $counts,
                'checks' => array_map(static fn (CheckResult $r): array => $r->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info('Launch readiness — '.app()->environment().' — docs/ops/LAUNCH-CHECKLIST.md');
        foreach ($results as $result) {
            $tag = match ($result->status) {
                CheckStatus::Pass => '<fg=green>PASS</>',
                CheckStatus::Warn => '<fg=yellow>WARN</>',
                CheckStatus::Fail => '<fg=red;options=bold>FAIL</>',
                CheckStatus::Manual => '<fg=cyan>MANUAL</>',
            };
            $this->components->twoColumnDetail("[{$result->ref}] {$result->title}", $tag);
            $this->line('    <fg=gray>'.$result->detail.'</>');
            if ($result->fix !== null && $result->status !== CheckStatus::Pass) {
                $this->line('    → '.$result->fix);
            }
        }
        $this->newLine();
        $summary = "{$counts['pass']} pass, {$counts['warn']} warn, {$counts['fail']} fail, {$counts['manual']} manual";
        $failed
            ? $this->components->error("NOT READY: {$summary}.")
            : $this->components->info("Automated checks ready: {$summary}. The manual items still need their evidence recorded.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
