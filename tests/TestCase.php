<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Lazy loads that are known and accepted, as "Model::relation". Phase 9 (docs/audits/PERFORMANCE.md):
     * every N+1 found by the suite was fixed with eager loading, so this list should stay empty —
     * add an entry only with a reason, and only for a path that can never iterate a collection.
     *
     * @var list<string>
     */
    private const LAZY_LOADING_ALLOWLIST = [];

    protected function setUp(): void
    {
        parent::setUp();

        // N+1 guard: Laravel flags a lazy load only on a model hydrated as part of a multi-row result,
        // which is exactly the N+1 shape. Every test runs with it on; a violation fails the test.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
            $key = $model::class.'::'.$relation;
            if (in_array($key, self::LAZY_LOADING_ALLOWLIST, true)) {
                return;
            }
            $log = getenv('LAZY_LOADING_LOG');
            if (is_string($log) && $log !== '') {
                // Survey mode (Phase 9): record every violation with its first app frame instead of failing.
                $frame = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
                    ->first(static fn (array $f): bool => isset($f['file']) && str_contains($f['file'], '/app/') && ! str_contains($f['file'], '/vendor/'));
                file_put_contents($log, $key.' @ '.($frame['file'] ?? '?').':'.($frame['line'] ?? '?').PHP_EOL, FILE_APPEND);

                return;
            }

            throw new LazyLoadingViolationException($model, $relation);
        });
    }
}
