<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidCertificate;
use finfo;

/**
 * Content checks for a compliance certificate (BSTI) upload, the media pipeline's
 * rules applied to PDFs (P4-CAT-07, SYSTEM-ARCHITECTURE §11 "file upload abuse"):
 *
 * - the type is sniffed from the bytes (finfo + the `%PDF-` magic), never the name or
 *   the client's MIME type;
 * - a PDF carrying active content (JavaScript, launch actions, embedded files,
 *   RichMedia or XFA) is refused outright;
 * - at most {@see self::MAX_BYTES}.
 *
 * Keyword scanning cannot see inside compressed object streams, so the file is also
 * kept private (originals disk) and only ever served as a download with
 * `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
 */
final class CertificateFiles
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const array ACTIVE_CONTENT = ['/javascript', '/js', '/launch', '/embeddedfile', '/richmedia', '/xfa'];

    /** @throws InvalidCertificate */
    public function inspect(string $path): int
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            throw new InvalidCertificate('The file is empty or unreadable.');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidCertificate(sprintf('The certificate is %.1f MB; the limit is %d MB.', $size / 1048576, intdiv(self::MAX_BYTES, 1048576)));
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $bytes = (string) file_get_contents($path);

        if ($mime !== 'application/pdf' || ! str_starts_with($bytes, '%PDF-')) {
            throw new InvalidCertificate('Only a PDF certificate is accepted.');
        }

        // Names in a PDF are case-sensitive, but a lower-case scan also catches the
        // `#4A`-free spellings a reader would accept; a false positive is just a re-export.
        $lower = strtolower($bytes);
        foreach (self::ACTIVE_CONTENT as $marker) {
            if (preg_match('#'.preg_quote($marker, '#').'(?![a-z])#', $lower) === 1) {
                throw new InvalidCertificate('The PDF contains scripts, attachments or interactive content and was refused. Export it again as a plain PDF.');
            }
        }

        return $size;
    }

    /** A safe display name: the client's basename, ASCII-folded, ending in .pdf. */
    public static function displayName(string $clientName): string
    {
        $base = pathinfo($clientName, PATHINFO_FILENAME);
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return mb_substr($safe === '' ? 'certificate' : $safe, 0, 180).'.pdf';
    }
}
