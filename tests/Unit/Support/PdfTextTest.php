<?php

declare(strict_types=1);

use Tests\Support\PdfText;

it('reads a compressed stream whose last byte is a newline', function (string $eol): void {
    // zlib's output for this content ends in a \n or \r byte (about 1 stream in 128 does). The
    // helper used to trim every trailing EOL byte, so gzuncompress() failed and the text came back
    // empty — the intermittent OrderEditDocumentsTest failure.
    // mPDF shows text as UTF-16BE (Identity-H), which is what PdfText decodes.
    $compressed = gzcompress('BT ('.mb_convert_encoding('INV-26-000001029', 'UTF-16BE', 'UTF-8').') Tj ET');
    expect(substr($compressed, -1))->toBeIn(["\n", "\r"]);

    $pdf = "%PDF-1.4\n1 0 obj\n<< /Filter /FlateDecode >>\nstream\n{$compressed}{$eol}endstream\nendobj\n";

    expect(PdfText::extract($pdf))->toContain('INV-26-000001029');
})->with(['LF' => "\n", 'CRLF' => "\r\n"]);
