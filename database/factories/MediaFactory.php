<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Media rows with no files on disk (a ready image with the 160–800 renditions recorded).
 * Tests that need real files upload through the API instead.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $renditions = [];
        foreach ([160, 320, 480, 800] as $w) {
            $renditions[] = ['w' => $w, 'f' => 'webp', 'b' => $w * 40];
            $renditions[] = ['w' => $w, 'f' => 'avif', 'b' => $w * 30];
        }

        return [
            'kind' => MediaKind::Image,
            'folder_id' => null,
            'filename' => 'toy-photo-'.fake()->unique()->numberBetween(1, 999999).'.jpg',
            'caption' => null,
            'alt_text' => 'Wooden puzzle, front view',
            'alt_text_bn' => 'কাঠের পাজল, সামনের দিক',
            'base_path' => 'media/'.Str::lower((string) Str::ulid()),
            'mime' => 'image/webp',
            'original_mime' => 'image/jpeg',
            'processing_status' => ImageProcessingStatus::Ready,
            'width' => 800,
            'height' => 800,
            'original_size_bytes' => 250000,
            'master_bytes' => 120000,
            'renditions_bytes' => array_sum(array_column($renditions, 'b')),
            'size_bytes' => 800 * 40,
            'renditions' => $renditions,
            'origin' => MediaOrigin::Library,
            'uploaded_by' => null,
        ];
    }

    public function document(): static
    {
        return $this->state(fn (): array => [
            'kind' => MediaKind::Document,
            'filename' => 'bsti-certificate-'.fake()->unique()->numberBetween(1, 999999).'.pdf',
            'alt_text' => null,
            'alt_text_bn' => null,
            'mime' => 'application/pdf',
            'original_mime' => 'application/pdf',
            'width' => null,
            'height' => null,
            'renditions' => null,
            'renditions_bytes' => 0,
            'size_bytes' => 90000,
            'master_bytes' => 90000,
            'original_size_bytes' => 90000,
        ]);
    }
}
