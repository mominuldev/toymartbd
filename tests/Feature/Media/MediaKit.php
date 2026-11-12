<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\ImageFixtures;

/** Shared fixtures for the media-library tests. */
final class MediaKit
{
    public const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public static function pdf(string $name = 'bsti-certificate.pdf', string $bytes = self::PDF): UploadedFile
    {
        return ImageFixtures::upload($bytes, $name, 'application/pdf');
    }

    public static function image(string $name = 'puzzle-front.jpg', int $w = 400, int $h = 400, string $format = 'jpeg'): UploadedFile
    {
        return ImageFixtures::upload(ImageFixtures::bytes($format, $w, $h), $name, 'image/'.$format);
    }

    /** @param array<string, mixed> $fields */
    public static function upload(UploadedFile $file, array $fields = [], string $locale = 'en'): TestResponse
    {
        return test()->post('/api/v1/admin/media', ['file' => $file, ...$fields], ['Accept' => 'application/json', 'Accept-Language' => $locale]);
    }
}
