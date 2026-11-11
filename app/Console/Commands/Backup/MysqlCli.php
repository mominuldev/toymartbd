<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs `mysqldump` / `mysql` for one Laravel connection without ever putting the
 * password on a command line (visible in `ps`) or in a log: credentials go into a
 * 0600 `--defaults-extra-file` that is deleted straight after.
 */
final readonly class MysqlCli
{
    /**
     * @param  array<string, mixed>  $connection  a config('database.connections.*') entry
     */
    public function __construct(
        private array $connection,
        private string $mysqldump,
        private string $mysql,
        private int $timeout,
    ) {
        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backups support the mysql driver only.');
        }
    }

    public function database(): string
    {
        return (string) $this->connection['database'];
    }

    public function charset(): string
    {
        return (string) ($this->connection['charset'] ?? 'utf8mb4');
    }

    public function collation(): string
    {
        return (string) ($this->connection['collation'] ?? 'utf8mb4_0900_ai_ci');
    }

    /** A consistent InnoDB snapshot of the whole database, without locking it. */
    public function dump(string $resultFile): void
    {
        $this->withDefaultsFile(function (string $defaults) use ($resultFile): void {
            $this->run([
                $this->mysqldump,
                '--defaults-extra-file='.$defaults,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--no-tablespaces',
                '--set-gtid-purged=OFF',
                '--default-character-set=utf8mb4',
                '--result-file='.$resultFile,
                $this->database(),
            ]);
        });
    }

    /** Pipes an SQL file into `$database` (defaults to the connection's own). */
    public function import(string $sqlFile, string $database): void
    {
        $input = fopen($sqlFile, 'rb');

        if ($input === false) {
            throw new RuntimeException('Cannot open the SQL dump for reading.');
        }

        try {
            $this->withDefaultsFile(function (string $defaults) use ($input, $database): void {
                $this->run([
                    $this->mysql,
                    '--defaults-extra-file='.$defaults,
                    '--default-character-set=utf8mb4',
                    $database,
                ], $input);
            });
        } finally {
            fclose($input);
        }
    }

    /** Runs one statement with no default database selected (CREATE / DROP DATABASE). */
    public function statement(string $sql): void
    {
        $this->withDefaultsFile(function (string $defaults) use ($sql): void {
            $this->run([$this->mysql, '--defaults-extra-file='.$defaults, '-e', $sql]);
        });
    }

    /**
     * @param  callable(string): void  $callback
     */
    private function withDefaultsFile(callable $callback): void
    {
        $lines = ['[client]'];
        $lines[] = 'user='.$this->quote((string) ($this->connection['username'] ?? ''));

        $password = (string) ($this->connection['password'] ?? '');
        if ($password !== '') {
            $lines[] = 'password='.$this->quote($password);
        }

        $socket = (string) ($this->connection['unix_socket'] ?? '');
        if ($socket !== '') {
            $lines[] = 'socket='.$this->quote($socket);
        } else {
            $lines[] = 'host='.$this->quote((string) ($this->connection['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($this->connection['port'] ?? 3306);
        }

        $path = tempnam(sys_get_temp_dir(), 'tmbd-my-');

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary MySQL options file.');
        }

        try {
            chmod($path, 0600);
            File::put($path, implode("\n", $lines)."\n");
            $callback($path);
        } finally {
            File::delete($path);
        }
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '\\"').'"';
    }

    /**
     * @param  list<string>  $command
     * @param  resource|null  $input
     */
    private function run(array $command, mixed $input = null): void
    {
        $process = new Process($command, timeout: $this->timeout);

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            // stderr from mysql/mysqldump names the user and host, never the password.
            throw new RuntimeException(basename($command[0]).' failed: '.trim($process->getErrorOutput()));
        }
    }
}
