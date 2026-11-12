<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Services;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes every WebP/AVIF rendition of the master at `{base}/master.webp` to the public
 * media disk (P4-CAT-07). Shared by the product-image job and the media-library job, so
 * there is one rendition code path. Never called inside a transaction (rule 4).
 */
final readonly class RenditionWriter
{
    public function __construct(private ImagePipeline $pipeline) {}

    /**
     * @return array{widths: list<int>, renditions: list<array{w: int, f: string, b: int}>, bytes: int, served: int}
     *
     * @throws RuntimeException when the master is missing
     */
    public function write(string $base): array
    {
        $master = Storage::disk(MediaUrls::originalsDisk())->get("{$base}/".Media::MASTER);

        if ($master === null) {
            throw new RuntimeException("Master missing for media base {$base}.");
        }

        $disk = Storage::disk(MediaUrls::disk());
        $renditions = [];
        $bytes = 0;
        $served = 0;

        $generated = $this->pipeline->renditions($master);

        foreach ($generated as $width => $formats) {
            foreach ($formats as $format => $data) {
                $disk->put(MediaUrls::renditionPath($base, $width, $format), $data, ['visibility' => 'public']);
                $size = strlen($data);
                $renditions[] = ['w' => $width, 'f' => $format, 'b' => $size];
                $bytes += $size;

                if ($format === 'webp') {
                    $served = $size; // ascending widths: the last WebP is the largest
                }
            }
        }

        return ['widths' => array_keys($generated), 'renditions' => $renditions, 'bytes' => $bytes, 'served' => $served];
    }
}
