<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product image (DATABASE-DESIGN §3.1). `path` is the rendition base on the media
 * disk (`products/{product}/{ulid}`): renditions live at `{path}/{width}.{webp|avif}`
 * (P4-CAT-07). It is null on rows with no file (seeded demo rows) — `url()` returns
 * null and consumers render a placeholder.
 *
 * **Linked to the media library** (`media_id`, docs/api/media-library.md): the media row
 * is the one source of truth for the file and the alt text. For a linked row, `path`,
 * `width`, `height`, `processing_status`, `rendition_widths`, `alt_text` and
 * `alt_text_bn` read from the media (accessors below), so a library edit or Replace
 * shows on every product at once. The row's own columns are a write-through mirror
 * (ProductImageMediaMirror) kept only for a safe rollback; an unlinked row (no file,
 * or not yet backfilled) still reads its own columns.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int|null $media_id
 * @property string|null $path
 * @property int|null $width
 * @property int|null $height
 * @property ImageProcessingStatus $processing_status
 * @property list<int>|null $rendition_widths
 * @property string|null $alt_text
 * @property string|null $alt_text_bn
 * @property int $position
 * @property bool $is_primary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Media|null $media
 */
final class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /**
     * Widths generated for every image (config media.widths), ascending. A source
     * narrower than a width is not upscaled; see `rendition_widths` for what exists.
     */
    public const array WIDTHS = [160, 320, 480, 800, 1200];

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'variant_id', 'media_id', 'path', 'width', 'height', 'processing_status', 'rendition_widths',
        'alt_text', 'alt_text_bn', 'position', 'is_primary',
    ];

    /** The media is read on every access to the file or alt fields. */
    protected $with = ['media'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'is_primary' => false,
        'processing_status' => 'ready',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'media_id' => 'integer',
            'position' => 'integer',
            'is_primary' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'processing_status' => ImageProcessingStatus::class,
            'rendition_widths' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /** The linked media, or null for an unlinked row. */
    public function linkedMedia(): ?Media
    {
        return $this->media_id === null ? null : $this->media;
    }

    /** @return Attribute<string|null, mixed> */
    protected function path(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            $media = $this->linkedMedia();

            return $media !== null ? $media->base_path : $value;
        })->withoutObjectCaching();
    }

    /** @return Attribute<int|null, mixed> */
    protected function width(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?int => $this->linkedMedia() !== null ? $this->linkedMedia()->width : self::intOrNull($value))->withoutObjectCaching();
    }

    /** @return Attribute<int|null, mixed> */
    protected function height(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?int => $this->linkedMedia() !== null ? $this->linkedMedia()->height : self::intOrNull($value))->withoutObjectCaching();
    }

    /** @return Attribute<ImageProcessingStatus, mixed> */
    protected function processingStatus(): Attribute
    {
        return Attribute::get(function (mixed $value): ImageProcessingStatus {
            $media = $this->linkedMedia();

            if ($media !== null) {
                return $media->processing_status;
            }

            return $value instanceof ImageProcessingStatus ? $value : (ImageProcessingStatus::tryFrom(is_string($value) ? $value : '') ?? ImageProcessingStatus::Ready);
        })->withoutObjectCaching();
    }

    /** @return Attribute<list<int>|null, mixed> */
    protected function renditionWidths(): Attribute
    {
        return Attribute::get(function (mixed $value): ?array {
            $media = $this->linkedMedia();

            if ($media !== null) {
                return $media->renditions === null ? null : $media->renditionWidths();
            }

            $decoded = is_string($value) ? json_decode($value, true) : $value;

            return is_array($decoded) ? array_values(array_map(intval(...), $decoded)) : null;
        })->withoutObjectCaching();
    }

    /** @return Attribute<string|null, mixed> */
    protected function altText(): Attribute
    {
        return Attribute::get(fn (?string $value): ?string => $this->media_id !== null && $this->media !== null ? $this->media->alt_text : $value)->withoutObjectCaching();
    }

    /** @return Attribute<string|null, mixed> */
    protected function altTextBn(): Attribute
    {
        return Attribute::get(fn (?string $value): ?string => $this->media_id !== null && $this->media !== null ? $this->media->alt_text_bn : $value)->withoutObjectCaching();
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Public URL of a rendition: the nearest generated width at or above `$width`
     * (or the largest when none is that wide, or when `$width` is null). Null while
     * the image has no file or its renditions are not generated yet.
     *
     * @param  'webp'|'avif'|string  $format
     */
    public function url(?int $width = null, string $format = 'webp'): ?string
    {
        if ($this->path === null || $this->path === '' || $this->processing_status !== ImageProcessingStatus::Ready) {
            return null;
        }

        $chosen = MediaUrls::pick($this->renditionWidthList(), $width);

        return $chosen === null ? null : MediaUrls::url(MediaUrls::renditionPath($this->path, $chosen, $format));
    }

    /**
     * `srcset` for one format ("…/160.webp 160w, …/320.webp 320w"), or null without renditions.
     */
    public function srcset(string $format = 'webp'): ?string
    {
        if ($this->url(null, $format) === null || $this->path === null) {
            return null;
        }

        $path = $this->path;

        return implode(', ', array_map(
            static fn (int $w): string => MediaUrls::url(MediaUrls::renditionPath($path, $w, $format))." {$w}w",
            $this->renditionWidthList(),
        ));
    }

    /** @return list<int> the widths that exist on disk, ascending */
    public function renditionWidthList(): array
    {
        $widths = array_map(intval(...), $this->rendition_widths ?? []);
        sort($widths);

        return $widths;
    }

    /**
     * Alt text for a locale (`en` or `bn`), falling back to the other language when
     * that one is empty; null when neither is set (compose "{product} — image {n}").
     */
    public function alt(string $locale): ?string
    {
        $en = $this->alt_text !== null && trim($this->alt_text) !== '' ? $this->alt_text : null;
        $bn = $this->alt_text_bn !== null && trim($this->alt_text_bn) !== '' ? $this->alt_text_bn : null;

        return $locale === 'bn' ? ($bn ?? $en) : ($en ?? $bn);
    }

    /** Both languages present — the publish rule (P4-CAT-06). */
    public function hasCompleteAlt(): bool
    {
        return $this->alt_text !== null && trim($this->alt_text) !== ''
            && $this->alt_text_bn !== null && trim($this->alt_text_bn) !== '';
    }

    protected static function newFactory(): ProductImageFactory
    {
        return ProductImageFactory::new();
    }
}
