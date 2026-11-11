<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Image fixtures built at test time with GD, so no binary blobs live in the repo.
 */
final class ImageFixtures
{
    /** A solid-colour image in the given format (`jpeg`, `png`, `webp`, `avif`). */
    public static function bytes(string $format = 'jpeg', int $width = 400, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        assert($image !== false);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 60));
        // A contrasting top-left block, so orientation can be asserted from pixels.
        imagefilledrectangle($image, 0, 0, intdiv($width, 4), intdiv($height, 4), (int) imagecolorallocate($image, 10, 200, 20));

        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 80),
            'avif' => imageavif($image, null, 60, 10),
            default => imagejpeg($image, null, 85),
        };

        return (string) ob_get_clean();
    }

    /**
     * A JPEG carrying an EXIF APP1 segment with Orientation = 6 (rotate 90° CW) and a
     * GPS latitude (Dhaka, 23°48'N) — what a phone photo looks like.
     */
    public static function jpegWithExifGps(int $width = 400, int $height = 200): string
    {
        $jpeg = self::bytes('jpeg', $width, $height);

        // TIFF header, big-endian; IFD0 at offset 8.
        $tiff = "MM\x00\x2A".pack('N', 8);
        // IFD0: 2 entries — Orientation (SHORT 6) and the GPS IFD pointer (LONG 38).
        $tiff .= pack('n', 2);
        $tiff .= pack('nnN', 0x0112, 3, 1).pack('nn', 6, 0);
        $tiff .= pack('nnNN', 0x8825, 4, 1, 38);
        $tiff .= pack('N', 0);
        // GPS IFD at 38: LatitudeRef "N", Latitude → 3 rationals at offset 68.
        $tiff .= pack('n', 2);
        $tiff .= pack('nnN', 0x0001, 2, 2)."N\x00\x00\x00";
        $tiff .= pack('nnNN', 0x0002, 5, 3, 68);
        $tiff .= pack('N', 0);
        $tiff .= pack('NNNNNN', 23, 1, 48, 1, 0, 1);

        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    public static function upload(string $bytes, string $name = 'photo.jpg', string $clientMime = 'image/jpeg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tmbimg');
        assert($path !== false);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $clientMime, null, true);
    }
}
