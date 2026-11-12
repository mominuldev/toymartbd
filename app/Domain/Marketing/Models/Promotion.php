<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Marketing\Enums\PromotionType;
use Database\Factories\PromotionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An automatic promotion (DATABASE-DESIGN §3.8, P4-CRT-02). Applied by
 * PromotionEngine in descending `priority`; a matching non-stackable promotion stops
 * the chain. `rules` per type: docs/api/cart.md.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property PromotionType $type
 * @property int $priority
 * @property bool $is_stackable
 * @property array<string, mixed> $rules
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property string|null $banner_path
 * @property int $usage_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name', 'name_bn', 'type', 'priority', 'is_stackable', 'rules', 'starts_at', 'ends_at', 'is_active', 'banner_path',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 0,
        'is_stackable' => true,
        'is_active' => true,
        'usage_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'priority' => 'integer',
            'is_stackable' => 'boolean',
            'rules' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
        ];
    }

    /**
     * Active and inside its window at `$at`, highest priority first (ties: oldest first).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function runningAt(Builder $query, DateTimeInterface $at): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByDesc('priority')
            ->orderBy('id');
    }

    protected static function newFactory(): PromotionFactory
    {
        return PromotionFactory::new();
    }
}
