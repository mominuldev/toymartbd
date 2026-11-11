<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit', 'Arch');

/**
 * Tests that only mean something on MySQL (collation, row locking). They skip on the
 * default SQLite run and execute under `make test-mysql`.
 */
function onlyOnMysql(): bool
{
    return DB::connection()->getDriverName() !== 'mysql';
}
