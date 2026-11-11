<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Services\MediaUrls;
use Database\Factories\ProductImageFactory;
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
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
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
        'product_id', 'variant_id', 'path', 'width', 'height', 'processing_status', 'rendition_widths',
        'alt_text', 'alt_text_bn', 'position', 'is_primary',
    ];

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

        $chosen = MediaUrls::pick($this->renditionWidths(), $width);

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
            $this->renditionWidths(),
        ));
    }

    /** @return list<int> the widths that exist on disk, ascending */
    public function renditionWidths(): array
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
