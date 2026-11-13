<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

/** One section of an export: a worksheet in XLSX, a titled block in CSV. */
final readonly class Sheet
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public function __construct(
        public string $title,
        public array $header,
        public iterable $rows,
    ) {}
}
