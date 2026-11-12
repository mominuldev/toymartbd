<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Models;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\AltStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Services\MediaUrls;
use App\Models\User;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One file in the media library (DATABASE-DESIGN §3.9, docs/api/media-library.md).
 *
 * `base_path` is the directory of one immutable file set: the private master
 * (`{base}/master.webp` for an image, `{base}/document.pdf` for a PDF) on the originals
 * disk and, for an image, the renditions `{base}/{width}.{webp|avif}` on the public
 * media disk. Replace moves the row to a new base; paths never change in place.
 *
 * @property int $id
 * @property MediaKind $kind
 * @property int|null $folder_id
 * @property string $filename
 * @property string|null $caption
 * @property string|null $alt_text
 * @property string|null $alt_text_bn
 * @property string $base_path
 * @property string $mime
 * @property string $original_mime
 * @property ImageProcessingStatus $processing_status
 * @property int|null $width
 * @property int|null $height
 * @property int $original_size_bytes
 * @property int $master_bytes
 * @property int $renditions_bytes
 * @property int $size_bytes
 * @property list<array{w: int, f: string, b: int}>|null $renditions
 * @property MediaOrigin $origin
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaFolder|null $folder
 * @property-read User|null $uploader
 */
final class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    public const string MASTER = 'master.webp';

    public const string DOCUMENT = 'document.pdf';

    protected $table = 'media';

    /** Set by the list/detail reads from MediaUsages (not a column). */
    public ?int $usedInCount = null;

    /** @var list<string> */
    protected $fillable = [
        'kind', 'folder_id', 'filename', 'caption', 'alt_text', 'alt_text_bn', 'base_path', 'mime', 'original_mime',
        'processing_status', 'width', 'height', 'original_size_bytes', 'master_bytes', 'renditions_bytes', 'size_bytes',
        'renditions', 'origin', 'uploaded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'processing_status' => 'ready',
        'origin' => 'library',
        'original_size_bytes' => 0,
        'master_bytes' => 0,
        'renditions_bytes' => 0,
        'size_bytes' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'origin' => MediaOrigin::class,
            'processing_status' => ImageProcessingStatus::class,
            'folder_id' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'original_size_bytes' => 'integer',
            'master_bytes' => 'integer',
            'renditions_bytes' => 'integer',
            'size_bytes' => 'integer',
            'renditions' => 'array',
            'uploaded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    /** Path of the stored original on the private originals disk. */
    public function originalPath(): string
    {
        return $this->base_path.'/'.($this->isImage() ? self::MASTER : self::DOCUMENT);
    }

    /** @return list<int> the widths generated, ascending */
    public function renditionWidths(): array
    {
        $widths = [];

        foreach ($this->renditions ?? [] as $rendition) {
            $widths[(int) $rendition['w']] = true;
        }

        $widths = array_keys($widths);
        sort($widths);

        return $widths;
    }

    /**
     * Public URL of a rendition: the nearest generated width ≥ `$width` (or the
     * largest). Null for documents and until the renditions are ready.
     */
    public function url(?int $width = null, string $format = 'webp'): ?string
    {
        if (! $this->isImage() || $this->processing_status !== ImageProcessingStatus::Ready) {
            return null;
        }

        $chosen = MediaUrls::pick($this->renditionWidths(), $width);

        return $chosen === null ? null : MediaUrls::url(MediaUrls::renditionPath($this->base_path, $chosen, $format));
    }

    public function srcset(string $format = 'webp'): ?string
    {
        if ($this->url(null, $format) === null) {
            return null;
        }

        $base = $this->base_path;

        return implode(', ', array_map(
            static fn (int $w): string => MediaUrls::url(MediaUrls::renditionPath($base, $w, $format))." {$w}w",
            $this->renditionWidths(),
        ));
    }

    /** The stable `/m/{id}/{slug}.webp` URL (decision 3); null for documents and until ready. */
    public function publicUrl(): ?string
    {
        if ($this->url() === null) {
            return null;
        }

        return url('/m/'.$this->id.'/'.$this->slugName().'.webp');
    }

    /** The filename stem as an ASCII slug, for URLs and download names. */
    public function slugName(): string
    {
        $slug = Str::slug(pathinfo($this->filename, PATHINFO_FILENAME));

        return $slug === '' ? 'file-'.$this->id : $slug;
    }

    public function downloadName(): string
    {
        $stem = pathinfo($this->filename, PATHINFO_FILENAME);
        $stem = $stem === '' ? 'file-'.$this->id : $stem;

        return $stem.($this->isImage() ? '.webp' : '.pdf');
    }

    public function altStatus(): AltStatus
    {
        if (! $this->isImage()) {
            return AltStatus::NotApplicable;
        }

        $en = self::filled($this->alt_text);
        $bn = self::filled($this->alt_text_bn);

        return match (true) {
            $en && $bn => AltStatus::Complete,
            $en => AltStatus::MissingBn,
            $bn => AltStatus::MissingEn,
            default => AltStatus::Missing,
        };
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }
}
