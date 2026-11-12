<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Customer\Models\Customer;
use App\Models\User;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product review (DATABASE-DESIGN §3.1, P4-CAT-12). `is_verified_purchase` is
 * derived, never accepted from a client: it is true only when `order_item_id` is a
 * delivered line of the reviewer's own order for this product
 * ({@see \App\Domain\Catalog\Reviews\Actions\SubmitReview}). New reviews are
 * `pending`; `products.rating_avg` / `rating_count` count approved ones only.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $customer_id
 * @property int|null $order_item_id
 * @property string $reviewer_name
 * @property int $rating
 * @property string|null $title
 * @property string|null $body
 * @property list<string>|null $images
 * @property bool $is_verified_purchase
 * @property ReviewStatus $status
 * @property string $locale
 * @property string|null $admin_reply
 * @property Carbon|null $admin_reply_at
 * @property int|null $moderated_by
 * @property Carbon|null $moderated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read Customer|null $customer
 * @property-read User|null $moderator
 */
final class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $table = 'reviews';

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'is_verified_purchase' => false,
        'locale' => 'en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'images' => 'array',
            'is_verified_purchase' => 'boolean',
            'status' => ReviewStatus::class,
            'admin_reply_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', ReviewStatus::Approved->value);
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }
}
