<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Catalog\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer testimonial shown by the homepage testimonials block.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $location
 * @property string|null $location_bn
 * @property string $quote
 * @property string|null $quote_bn
 * @property int|null $rating
 * @property int|null $media_id
 * @property bool $is_published
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $media
 */
final class Testimonial extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name', 'name_bn', 'location', 'location_bn', 'quote', 'quote_bn', 'rating', 'media_id', 'is_published', 'position',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_published' => false, 'position' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'media_id' => 'integer',
            'is_published' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
