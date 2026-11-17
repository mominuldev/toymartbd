<?php

declare(strict_types=1);

use Tests\Support\PdfText;

/*
 * The invoice tests failed now and then with the PDF reading back as empty text. Each case here
 * is one way PdfText lost a stream; the fixture is a real mPDF invoice that read back empty.
 */

it('reads the mPDF invoice whose compressed page stream ends in \r', function (): void {
    // Captured from a failing parallel run: the page's zlib data ends in "\r", mPDF then writes
    // "\n", and stripping that "\r\n" as one line ending cut a byte of real data.
    $text = PdfText::extract((string) file_get_contents(__DIR__.'/pdftext-cr-before-eol.pdf'));

    expect($text)->toContain('Freight')->toContain('PINV-26-000002')->toContain('Purchase order');
});

it('reads a compressed stream whose own last byte is \n or \r', function (string $content, bool $withLength): void {
    // mPDF shows text as UTF-16BE (Identity-H), which is what PdfText decodes.
    $compressed = gzcompress('BT ('.mb_convert_encoding($content, 'UTF-16BE', 'UTF-8').') Tj ET');
    expect(substr($compressed, -1))->toBeIn(["\n", "\r"]);

    $dict = $withLength ? '<< /Length '.strlen($compressed).' /Filter /FlateDecode >>' : '<< /Filter /FlateDecode >>';
    $pdf = "%PDF-1.4\n1 0 obj\n{$dict}\nstream\n{$compressed}\nendstream\nendobj\n";

    expect(PdfText::extract($pdf))->toContain($content);
})->with([
    // Fixed inputs whose zlib output ends in \n and \r respectively, so the cases are deterministic.
    'ends in \n, with /Length' => ['INV-26-000001029', true],
    'ends in \n, no /Length' => ['INV-26-000001029', false],
    'ends in \r, with /Length' => [fn (): string => pdfTextCrContent(), true],
    'ends in \r, no /Length' => [fn (): string => pdfTextCrContent(), false],
]);

it('returns valid UTF-8 even when a stream shows raw bytes, so a /u regex over it still works', function (): void {
    // An odd-length string is passed through undecoded; "\xFF\xFE\xFD" is not UTF-8.
    $text = mb_convert_encoding('৳62,150', 'UTF-16BE', 'UTF-8');
    $pdf = "%PDF-1.4\n1 0 obj\n<< >>\nstream\nBT (\xFF\xFE\xFD) Tj ({$text}) Tj ET\nendstream\nendobj\n";

    $extracted = PdfText::extract($pdf);

    expect(mb_check_encoding($extracted, 'UTF-8'))->toBeTrue()
        ->and((string) preg_replace('/\s+/u', ' ', $extracted))->toContain('৳62,150');
});

/** The first invoice number from 1000 up whose compressed text stream ends in "\r". */
function pdfTextCrContent(): string
{
    for ($i = 1000; $i < 100000; $i++) {
        $content = "INV-26-00000{$i}";
        if (str_ends_with((string) gzcompress('BT ('.mb_convert_encoding($content, 'UTF-16BE', 'UTF-8').') Tj ET'), "\r")) {
            return $content;
        }
    }

    throw new RuntimeException('No input found whose compressed stream ends in \r.');
}
