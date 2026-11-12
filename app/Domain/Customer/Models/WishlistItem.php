<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A toy a customer saved (P4-STO-11). Holds no price or stock: the wishlist reads both
 * live from the catalog so it never shows a stale figure.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $product_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 */
final class WishlistItem extends Model
{
    /** A customer's list is capped so one account cannot grow it without bound. */
    public const int MAX_ITEMS = 200;

    /** @var list<string> */
    protected $fillable = ['customer_id', 'product_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'product_id' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
