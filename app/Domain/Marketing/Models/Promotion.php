<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Marketing\Enums\PromotionType;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\PromotionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An automatic promotion (DATABASE-DESIGN §3.8, P4-CRT-02, P7). Applied by
 * PromotionEngine in descending `priority`; a matching non-stackable promotion stops
 * the chain. `allows_coupons` decides whether a coupon may also discount the lines it
 * discounted. `usage_count` / `discount_spent` are maintained by PromotionUsages under
 * this row's lock. `rules` per type: docs/api/promotions.md.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $description
 * @property PromotionType $type
 * @property int $priority
 * @property bool $is_stackable
 * @property bool $allows_coupons
 * @property array<string, mixed> $rules
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property string|null $banner_path
 * @property int $usage_count
 * @property int|null $usage_limit_total
 * @property int|null $usage_limit_per_customer
 * @property Money|null $budget
 * @property Money $discount_spent
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, FlashSaleItem> $flashItems
 */
final class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name', 'name_bn', 'description', 'type', 'priority', 'is_stackable', 'allows_coupons', 'rules', 'starts_at', 'ends_at',
        'is_active', 'banner_path', 'usage_limit_total', 'usage_limit_per_customer', 'budget', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 0,
        'is_stackable' => true,
        'allows_coupons' => true,
        'is_active' => true,
        'usage_count' => 0,
        'discount_spent' => '0.00',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'priority' => 'integer',
            'is_stackable' => 'boolean',
            'allows_coupons' => 'boolean',
            'rules' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
            'usage_limit_total' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'budget' => MoneyCast::class,
            'discount_spent' => MoneyCast::class,
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<FlashSaleItem, $this> */
    public function flashItems(): HasMany
    {
        return $this->hasMany(FlashSaleItem::class)->orderBy('variant_id');
    }

    /** @return HasMany<PromotionUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    /** Budget left (null = no budget). Never negative. */
    public function budgetLeft(): ?Money
    {
        if ($this->budget === null) {
            return null;
        }
        $left = $this->budget->minus($this->discount_spent);

        return $left->isNegative() ? Money::zero() : $left;
    }

    public function totalLimitReached(): bool
    {
        return $this->usage_limit_total !== null && $this->usage_count >= $this->usage_limit_total;
    }

    /** `scheduled` | `running` | `ended` | `inactive` at `$at`. */
    public function statusAt(DateTimeInterface $at): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at !== null && $this->starts_at->greaterThan($at) => 'scheduled',
            $this->ends_at !== null && ! $this->ends_at->greaterThan($at) => 'ended',
            default => 'running',
        };
    }

    /**
     * Active and inside its window at `$at`, highest priority first (ties: oldest first).
     * The window is half-open: running from `starts_at` inclusive to `ends_at` exclusive.
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
