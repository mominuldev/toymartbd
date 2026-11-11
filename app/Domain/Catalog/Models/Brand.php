<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\BrandStatus;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A toy brand (DATABASE-DESIGN §3.1). Archived, never deleted: products hold a
 * `brand_id`. The slug is ASCII and shared by both locales (rules 26/37).
 *
 * TODO(P4-SEO): write `slug_history` on a slug change once the Seo domain's
 * slug_history table and 301 middleware exist. BrandChanged already carries the
 * previous slug.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $description
 * @property string|null $description_bn
 * @property string|null $country
 * @property BrandStatus $status
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /** Generated slugs leave room for a "-NNN" uniqueness suffix within the 94-char column. */
    public const int SLUG_BASE_MAX = 90;

    public const int SLUG_MAX = 94;

    /** @var list<string> */
    protected $fillable = [
        'name', 'name_bn', 'slug', 'logo_path', 'description', 'description_bn', 'country', 'status', 'position',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BrandStatus::class,
            'position' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === BrandStatus::Active;
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', BrandStatus::Active->value);
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }
}
