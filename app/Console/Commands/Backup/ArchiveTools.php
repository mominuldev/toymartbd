<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * tar + `openssl enc` wrappers. Standard tools on purpose: an operator can restore
 * a backup by hand on a bare host with nothing but this command —
 *
 *   openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -iter 600000 \
 *     -in X.tar.gz.enc -out X.tar.gz -pass env:BACKUP_ARCHIVE_PASSWORD
 *
 * The password reaches openssl through its environment (`-pass env:`), never argv.
 */
final readonly class ArchiveTools
{
    private const string PASSWORD_ENV = 'TOYMART_BACKUP_ARCHIVE_PASSWORD';

    public function __construct(
        private string $tar,
        private string $openssl,
        private int $iterations,
        private int $timeout,
    ) {}

    /**
     * @param  array<string, list<string>>  $sources  base directory => entries inside it
     */
    public function tar(string $archive, array $sources): void
    {
        $command = [$this->tar, '-czf', $archive];

        foreach ($sources as $directory => $entries) {
            if ($entries === []) {
                continue;
            }

            $command[] = '-C';
            $command[] = $directory;
            array_push($command, ...$entries);
        }

        // COPYFILE_DISABLE stops macOS bsdtar adding AppleDouble `._*` files.
        $this->run($command, ['COPYFILE_DISABLE' => '1']);
    }

    public function untar(string $archive, string $destination): void
    {
        $this->run([$this->tar, '-xzf', $archive, '-C', $destination]);
    }

    public function encrypt(string $in, string $out, string $password): void
    {
        $this->run([...$this->opensslArgs(), '-salt', '-in', $in, '-out', $out], [self::PASSWORD_ENV => $password]);
    }

    public function decrypt(string $in, string $out, string $password): void
    {
        $this->run([...$this->opensslArgs(), '-d', '-in', $in, '-out', $out], [self::PASSWORD_ENV => $password]);
    }

    /** @return list<string> */
    private function opensslArgs(): array
    {
        return [
            $this->openssl, 'enc', '-aes-256-cbc', '-pbkdf2', '-md', 'sha256',
            '-iter', (string) $this->iterations, '-pass', 'env:'.self::PASSWORD_ENV,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(array $command, array $env = []): void
    {
        $process = new Process($command, env: $env, timeout: $this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(basename($command[0]).' failed: '.trim($process->getErrorOutput()));
        }
    }
}
