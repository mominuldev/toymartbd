<?php

declare(strict_types=1);

use App\Support\CsvReport;

/*
 * CSV formula injection (OWASP): =, +, -, @, tab and carriage return all start a formula
 * in some spreadsheet; each is neutralised with a leading apostrophe. P9 security review
 * added tab and CR.
 */

it('neutralises every formula trigger but leaves our own numbers numeric', function (): void {
    $response = CsvReport::download('r.csv', ['value'], [
        ['=HYPERLINK("http://evil")'], ['+1+1'], ['-2+3'], ['@SUM(A1)'], ["\t=1+1"], ["\r=1+1"], ['-1280.00'], ['রহিম'],
    ]);

    expect((string) file_get_contents($response->getFile()->getPathname()))
        ->toContain("'=HYPERLINK")
        ->toContain("'+1+1")
        ->toContain("'-2+3")
        ->toContain("'@SUM")
        ->toContain("'\t=1+1")
        ->toContain("'\r=1+1")
        ->toContain('-1280.00')
        ->not->toContain("'-1280.00")
        ->toContain('রহিম');
});
