<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Real parallelism for the MySQL race tests (P9). RefreshDatabase wraps each test in a
 * transaction no other connection can see, so a race builds a throwaway database next to
 * the test one (`<db>_<suffix>`), migrates it, commits its fixtures, forks one process per
 * job behind a start barrier, then drops it — the pattern CheckoutConcurrencyTest and
 * InvoiceNumberRaceTest introduced, shared here.
 *
 * Usage: `ForkRace::open('order_numbers')` in beforeEach, `ForkRace::close()` in afterEach,
 * `ForkRace::run([...closures])` in the test. Each closure runs in its own process with its
 * own connection; its return value (or `error: <class> <message>`) comes back as a string.
 */
final class ForkRace
{
    private static ?string $connection = null;

    private static ?string $original = null;

    public static function open(string $suffix): void
    {
        $database = config('database.connections.mysql.database').'_'.$suffix;
        self::$original = DB::getDefaultConnection();
        self::$connection = 'race_'.$suffix;

        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.$database.'`');
        DB::connection('mysql')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        config(['database.connections.'.self::$connection => ['database' => $database] + config('database.connections.mysql')]);
        DB::setDefaultConnection(self::$connection);
        Artisan::call('migrate', ['--database' => self::$connection, '--force' => true]);
    }

    public static function close(): void
    {
        if (self::$connection === null || self::$original === null) {
            return;
        }
        $database = config('database.connections.'.self::$connection.'.database');
        DB::purge(self::$connection);
        DB::setDefaultConnection(self::$original);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.$database.'`');
        self::$connection = null;
        self::$original = null;
    }

    /**
     * @param  list<Closure(): (string|int|null)>  $jobs
     * @return list<string> one result per job, in job order
     */
    public static function run(array $jobs): array
    {
        $dir = sys_get_temp_dir().'/fork-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $go = $dir.'/go';

        // Fixtures are bound to the race connection; a child that inherited the parent's
        // open PDO would talk over the parent's socket (CheckoutConcurrencyTest). Close it:
        // every child opens its own connection lazily, the parent reconnects afterwards.
        DB::disconnect();

        $pids = [];
        foreach ($jobs as $i => $job) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }
                try {
                    $result = (string) $job();
                } catch (Throwable $e) {
                    $result = 'error: '.$e::class.' '.$e->getMessage();
                }
                file_put_contents($dir.'/'.$i, $result);
                // SIGKILL: no destructor may touch anything the parent still holds.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        touch($go);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach (array_keys($jobs) as $i) {
            $results[] = is_file($dir.'/'.$i) ? (string) file_get_contents($dir.'/'.$i) : 'no result';
            @unlink($dir.'/'.$i);
        }
        @unlink($go);
        @rmdir($dir);

        return $results;
    }

    /** InnoDB's lifetime deadlock counter (INNODB_METRICS `lock_deadlocks`), or null off MySQL. */
    public static function deadlocks(): ?int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }
        $value = DB::selectOne("SELECT `count` AS c FROM information_schema.INNODB_METRICS WHERE name = 'lock_deadlocks'");

        return $value === null ? null : (int) $value->c;
    }
}
