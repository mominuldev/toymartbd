<?php

declare(strict_types=1);

namespace App\Domain\Cart\Models;

use App\Domain\Marketing\Models\Coupon;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A server-side cart (DATABASE-DESIGN §3.4, P4-CRT-01), found by its opaque `token`
 * (the Next.js server keeps it in an httpOnly cookie and forwards it as
 * `X-Cart-Token`). The money columns cache the last priced result; the API and
 * checkout always re-price through PricingPipeline.
 *
 * @property int $id
 * @property string $token
 * @property int|null $customer_id
 * @property string|null $phone E.164 contact a guest left for recovery (P7)
 * @property bool $marketing_consent the guest opted in to one cart reminder (P7)
 * @property string|null $locale
 * @property Carbon|null $contact_captured_at
 * @property int|null $coupon_id
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount_total
 * @property Money $shipping_total
 * @property Money $grand_total
 * @property int $item_count
 * @property int|null $shipping_address_id
 * @property string|null $notes
 * @property Carbon|null $abandoned_at
 * @property Carbon|null $recovery_email_sent_at
 * @property int|null $recovered_order_id
 * @property Carbon|null $converted_at
 * @property int|null $order_id
 * @property int|null $merged_into_cart_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CartItem> $items
 * @property-read Coupon|null $coupon
 */
final class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /** A cart lives this long after its last change. */
    public const int LIFETIME_DAYS = 30;

    /** Per line: a toy shop, not a wholesaler (wholesale orders go through staff). */
    public const int MAX_LINE_QUANTITY = 99;

    /** Distinct lines per cart. */
    public const int MAX_LINES = 50;

    /** @var list<string> */
    protected $fillable = ['token', 'customer_id', 'coupon_id', 'currency', 'expires_at', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'BDT',
        'subtotal' => '0.00',
        'discount_total' => '0.00',
        'shipping_total' => '0.00',
        'grand_total' => '0.00',
        'item_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'marketing_consent' => 'boolean',
            'contact_captured_at' => 'datetime',
            'coupon_id' => 'integer',
            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'shipping_total' => MoneyCast::class,
            'grand_total' => MoneyCast::class,
            'item_count' => 'integer',
            'abandoned_at' => 'datetime',
            'recovery_email_sent_at' => 'datetime',
            'recovered_order_id' => 'integer',
            'converted_at' => 'datetime',
            'order_id' => 'integer',
            'merged_into_cart_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Variant id → quantity, the pricing pipeline's input.
     *
     * @return array<int, int>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->items as $item) {
            $lines[$item->variant_id] = $item->quantity;
        }

        return $lines;
    }

    /**
     * The placement that added each line, by variant id (rule 25d); null = an ordinary add.
     *
     * @return array<int, string|null>
     */
    public function recommendationSources(): array
    {
        $sources = [];
        foreach ($this->items as $item) {
            $sources[$item->variant_id] = $item->recommendation_source?->value;
        }

        return $sources;
    }

    public function extendLifetime(): void
    {
        $this->expires_at = now()->addDays(self::LIFETIME_DAYS);
    }

    /**
     * Still usable: not expired, not turned into an order, not merged into another cart.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('converted_at')
            ->whereNull('merged_into_cart_id')
            ->where('expires_at', '>', now());
    }

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }
}
