<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Catalog\Exceptions\InvalidCertificate;
use App\Domain\Catalog\Services\CertificateFiles;
use App\Domain\Catalog\Services\MediaUrls;
use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Expense receipts: a PDF (the certificate rules — sniffed, no active content) or a
 * JPEG/PNG/WebP photo of a paper receipt, ≤ 10 MB. Kept on the private originals disk under
 * `finance/receipts/` and only ever served as a download (`nosniff`, `no-store`).
 */
final readonly class ExpenseAttachments
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const array IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private CertificateFiles $pdfs) {}

    /**
     * The sniffed MIME type of an acceptable receipt.
     *
     * @throws InvalidArgumentException with a staff-facing message
     */
    public function inspect(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new InvalidArgumentException('The file is empty or unreadable.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('The receipt is larger than 10 MB.');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === 'application/pdf') {
            try {
                $this->pdfs->inspect($path);
            } catch (InvalidCertificate $e) {
                throw new InvalidArgumentException($e->getMessage(), 0, $e);
            }

            return $mime;
        }
        if (! array_key_exists($mime, self::IMAGE_TYPES) || @getimagesize($path) === false) {
            throw new InvalidArgumentException('A receipt must be a PDF, JPEG, PNG or WebP file.');
        }

        return $mime;
    }

    /**
     * Store a receipt; returns [path, mime, size].
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public function store(string $path): array
    {
        $mime = $this->inspect($path);
        $ext = self::IMAGE_TYPES[$mime] ?? 'pdf';
        $key = 'finance/receipts/'.Str::uuid()->toString().'.'.$ext;
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('The file is unreadable.');
        }
        Storage::disk(MediaUrls::originalsDisk())->put($key, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return [$key, $mime, (int) filesize($path)];
    }

    public function delete(?string $key): void
    {
        if ($key !== null && $key !== '') {
            Storage::disk(MediaUrls::originalsDisk())->delete($key);
        }
    }

    /** A safe download name: the client's basename, ASCII-folded, with the stored extension. */
    public static function displayName(string $clientName, string $mime): string
    {
        $base = pathinfo($clientName, PATHINFO_FILENAME);
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return mb_substr($safe === '' ? 'receipt' : $safe, 0, 180).'.'.(self::IMAGE_TYPES[$mime] ?? 'pdf');
    }
}
