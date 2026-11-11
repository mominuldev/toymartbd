<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Decorates a handler's formatter and masks phone numbers in the rendered output.
 *
 * The processor cannot rewrite a Throwable's message without destroying its trace,
 * and a QueryException's message carries its SQL bindings — a customer's phone
 * among them. Masking the final text catches that last path.
 */
final readonly class RedactingFormatter implements FormatterInterface
{
    public function __construct(private FormatterInterface $inner) {}

    public function format(LogRecord $record): mixed
    {
        return $this->clean($this->inner->format($record));
    }

    /** @param  array<LogRecord>  $records */
    public function formatBatch(array $records): mixed
    {
        return $this->clean($this->inner->formatBatch($records));
    }

    public function inner(): FormatterInterface
    {
        return $this->inner;
    }

    private function clean(mixed $output): mixed
    {
        return match (true) {
            is_string($output) => RedactSensitiveData::scrubText($output),
            is_array($output) => RedactSensitiveData::redactValue($output),
            default => $output,
        };
    }
}
