<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RiskLevel;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use App\Support\PhoneNumber;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An order (DATABASE-DESIGN §3.4, P4-ORD-01). Never deleted (rule 12). Its three status
 * columns change only through {@see \App\Domain\Ordering\Services\OrderTransitions}: a
 * save that changes one any other way throws.
 *
 * `shipping_phone` is E.164; show {@see maskedShippingPhone()} unless the viewer holds
 * customers.view_pii, and never log either the phone or the address (rule 20).
 *
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property int|null $cart_id
 * @property OrderStatus $status
 * @property OrderPaymentStatus $payment_status
 * @property FulfillmentStatus $fulfillment_status
 * @property OrderSource $source
 * @property string|null $channel_reference
 * @property string|null $payment_method
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount_total
 * @property string|null $coupon_code
 * @property Money $coupon_discount
 * @property Money $promotion_discount
 * @property Money $shipping_charge
 * @property Money $shipping_discount
 * @property Money $tax_total
 * @property Money $grand_total
 * @property Money $paid_total
 * @property Money $due_total
 * @property Money $refunded_total
 * @property string $shipping_name
 * @property string $shipping_phone
 * @property string|null $shipping_alt_phone
 * @property int|null $shipping_division_id
 * @property int|null $shipping_district_id
 * @property int|null $shipping_upazila_id
 * @property string|null $shipping_division
 * @property string|null $shipping_district
 * @property string|null $shipping_upazila
 * @property string|null $shipping_area
 * @property string $shipping_address
 * @property string|null $shipping_landmark
 * @property string|null $shipping_postcode
 * @property string|null $customer_note
 * @property string|null $internal_note
 * @property bool $is_gift
 * @property int|null $risk_score
 * @property RiskLevel|null $risk_level
 * @property array<string, mixed>|null $risk_flags
 * @property VerificationStatus $verification_status
 * @property Carbon|null $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $packed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $returned_at
 * @property CancellationReason|null $cancel_reason
 * @property string|null $cancel_note
 * @property string|null $return_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderEvent> $events
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Refund> $refunds
 * @property-read OrderFinancial|null $financials
 */
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    private const array STATUS_COLUMNS = ['status', 'payment_status', 'fulfillment_status'];

    /** Set only by OrderTransitions for the duration of one save. */
    private bool $transitioning = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'fulfillment_status' => 'unfulfilled',
        'source' => 'web',
        'currency' => 'BDT',
        'verification_status' => 'not_required',
        'is_gift' => false,
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $order): void {
            if (! $order->transitioning && $order->isDirty(self::STATUS_COLUMNS)) {
                throw OrderViolation::statusOutsideStateMachine();
            }
        });
        self::deleting(static fn (): never => throw OrderViolation::neverDeleted());
    }

    /**
     * Save a status change. Only OrderTransitions calls this, after the state machine has
     * approved the move.
     *
     * @internal
     */
    public function saveTransition(): void
    {
        $this->transitioning = true;
        try {
            $this->save();
        } finally {
            $this->transitioning = false;
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        $money = array_fill_keys([
            'subtotal', 'discount_total', 'coupon_discount', 'promotion_discount', 'shipping_charge',
            'shipping_discount', 'tax_total', 'grand_total', 'paid_total', 'due_total', 'refunded_total',
        ], MoneyCast::class);

        return $money + [
            'customer_id' => 'integer',
            'cart_id' => 'integer',
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'source' => OrderSource::class,
            'shipping_division_id' => 'integer',
            'shipping_district_id' => 'integer',
            'shipping_upazila_id' => 'integer',
            'is_gift' => 'boolean',
            'risk_score' => 'integer',
            'risk_level' => RiskLevel::class,
            'risk_flags' => 'array',
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancel_reason' => CancellationReason::class,
            'created_by' => 'integer',
        ];
    }

    /** 017****5678 (rule 20). */
    public function maskedShippingPhone(): string
    {
        return PhoneNumber::tryParse($this->shipping_phone)?->masked() ?? '***';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** @return HasOne<OrderFinancial, $this> */
    public function financials(): HasOne
    {
        return $this->hasOne(OrderFinancial::class);
    }

    /**
     * Variant id → quantity still held as a reservation (lines whose variant still exists),
     * ascending by variant id.
     *
     * @return array<int, int>
     */
    public function reservedLines(): array
    {
        $lines = [];
        foreach ($this->items as $item) {
            $open = $item->quantity - $item->quantity_shipped;
            if ($item->variant_id !== null && $open > 0) {
                $lines[$item->variant_id] = ($lines[$item->variant_id] ?? 0) + $open;
            }
        }
        ksort($lines);

        return $lines;
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
