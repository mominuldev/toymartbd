<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

/** One section of an export: a worksheet in XLSX, a titled block in CSV. */
final readonly class Sheet
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     * @param  list<string>  $text  header keys whose cells stay text in XLSX even when they look
     *                              like a number (a SKU "00123", a district code) — never a sum
     */
    public function __construct(
        public string $title,
        public array $header,
        public iterable $rows,
        public array $text = [],
    ) {}
}
